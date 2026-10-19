<?php
declare(strict_types=1);

/** Own finite backend primitives only. Importing this helper starts no App or process. */
const M4UIB_ENTRY='help/audit-regressions/isolated/ms4-ui-backend.php';
const M4UIB_HELPER='help/audit-regressions/isolated/lib/ms4-ui-backend-normal.php';
const M4UIB_META='help/audit-regressions/isolated/ms4-ui-credential-meta.php';
const M4UIB_IMAGE='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const M4UIB_GATEWAY_IMAGE='sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236';
const M4UIB_ROOTS=['crmeb/app','crmeb/crmeb','crmeb/vendor','crmeb/config'];
const M4UIB_BOOTSTRAP=['help/audit-regressions/isolated/ms4-customer-http-normal.php'=>['bytes'=>96225,'sha256'=>'8f243fdeadc42335827642eec060d506e137e0e1c5ff01fa4d5db8ea9e69543a'],'help/audit-regressions/isolated/lib/harness.php'=>['bytes'=>48344,'sha256'=>'d1f6edf1e08d92f20e99954c41f4dafbb1bf164aeba249c1ef859710af73aa0d'],'help/audit-regressions/isolated/lib/guard.php'=>['bytes'=>4694,'sha256'=>'a7a182c3a204dbb55f5669a61f44af099360223acd9835f82d9da3a8bf2f79a8'],'help/audit-regressions/isolated/lib/ms4-customer-http-normal.php'=>['bytes'=>63896,'sha256'=>'5ba793e50cf604342304ff2563141384c8a61ffd2dc796865207210d70a946f9']];
const M4UIB_NATIVE_KEYS=['id','image','hostname','user','stop_signal','labels','mounts','tmpfs','read_only','privileged','network_mode','status','command','entrypoint','workdir','restart_count','pid','exit_code','networks','ports','port_bindings','cap_drop','security_options','started_at','finished_at','created_at'];
const M4UIB_INSPECT_FORMAT='{"id":{{json .Id}},"image":{{json .Image}},"hostname":{{json .Config.Hostname}},"user":{{json .Config.User}},"stop_signal":{{json (index .Config "StopSignal")}},"labels":{{json .Config.Labels}},"mounts":{{json .Mounts}},"tmpfs":{{json (index .HostConfig "Tmpfs")}},"read_only":{{json .HostConfig.ReadonlyRootfs}},"privileged":{{json .HostConfig.Privileged}},"network_mode":{{json .HostConfig.NetworkMode}},"status":{{json .State.Status}},"command":{{json .Config.Cmd}},"entrypoint":{{json .Config.Entrypoint}},"workdir":{{json .Config.WorkingDir}},"restart_count":{{json .RestartCount}},"pid":{{json .State.Pid}},"exit_code":{{json .State.ExitCode}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}},"port_bindings":{{json .HostConfig.PortBindings}},"cap_drop":{{json .HostConfig.CapDrop}},"security_options":{{json .HostConfig.SecurityOpt}},"started_at":{{json .State.StartedAt}},"finished_at":{{json .State.FinishedAt}},"created_at":{{json .Created}}}';
const M4UIB_IMMUTABLE=['id','image','hostname','labels','tmpfs','read_only','privileged','network_mode','command','entrypoint','workdir','port_bindings','cap_drop','security_options','created_at','user','stop_signal'];
const M4UIB_ENV_KEYS=['APP_ENV','PHP_CACHE_DRIVER','PHP_MARKETPLACE_AFTERSALE_ENABLED','PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_MARKETPLACE_TRADE_ENABLED'];
const M4UIB_BINDING_KEYS=['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256','requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256','manifest','environment','environment_native','history','public_materials','volumes_native'];
const M4UIB_SEAL_KEYS=['schema','scope','run_id','source_sha','freeze_sha256','prepare_container_id','prepare_context','fixture_volume','original_formal_manifest','prepare_public_files','paid_predecessor_files'];
const M4UIB_PREPARE_KEYS=['schema','scope','status','run_id','source_sha','container_id','freeze_sha256','native_created_sha256','source_binding_sha256','environment_sha256','environment_native_sha256','phase','entry_return_code','termination','native_exit','native_exit_observation','full_source_unchanged','business','child','diagnostic','three_end_login_DOM','N1_fulfillment_review_business','full_acceptance','stopped_samples','assertions'];
const M4UIB_BUSINESS_KEYS=['prepared_only','paid_order_id','paid_order_no','merchant_id','customer_id','item_quantities','source_fingerprint','actor_ids','writer','menus','window_before','window_final','independent_full_window_verified','post_child_close_verified','observer'];
const M4UIB_WRITER_KEYS=['path','bytes','sha256','mode','uid','gid','dev','ino','nlink','directory_mode'];
const M4UIB_PREPARE_NAMES=['ui-prepare-created.json','ms4-ui-prepare-source-binding.json','ui-prepare-environment.json','ui-prepare-environment-native.json','ms4-ui-prepare.json','ui-prepare-shutdown.json','ui-prepare-terminal.json'];
const M4UIB_VOLUME_MOUNTS=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',false],'/var/lib/crmeb-aftersale-evidence'=>['aftersale-evidence',true],'/workspace/crmeb/public'=>['ui-public',false],'/workspace/crmeb/public/uploads/store/comment'=>['ui-comments',true],'/run/audit-ui-control'=>['ui-control',true]];
const M4UIB_ORIGINAL_VOLUMES=['aftersale-evidence','app-secrets','bootstrap-secrets','fixture-secrets','mysql-data','redis-data','redis-secrets'];
const M4UIB_CONTROL='/run/audit-ui-control';
const M4UIB_LOGS=['/workspace/crmeb/runtime/ms4-ui-backend.stdout.log','/workspace/crmeb/runtime/ms4-ui-backend.stderr.log'];
const M4UIB_CURRENT_KEYS=['schema','scope','run_id','source_sha','freeze_sha256','container_id','native_created_sha256','source_binding_sha256','projection','native'];
const M4UIB_NATIVE_RECEIPT_KEYS=['label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256'];
const M4UIB_RESULT_KEYS=['schema','scope','status','run_id','source_sha','container_id','freeze_sha256','native_created_sha256','source_binding_sha256','environment_sha256','environment_native_sha256','prepare_history_sha256','ready_sha256','phase','entry_return_code','termination','native_exit','native_exit_observation','full_source_unchanged','child','stop_kind','diagnostic','three_end_login_DOM','business_acceptance','full_acceptance','stopped_samples','assertions'];
const M4UIB_SHUTDOWN_KEYS=['schema','scope','run_id','source_sha','container_id','freeze_sha256','phase','completed','result_published','fatal','termination_requested','primary','cleanup','child','source_unchanged'];
const M4UIB_PREPARE_SHUTDOWN_KEYS=['schema','scope','run_id','source_sha','container_id','freeze_sha256','phase','completed','result_published','fatal','termination_requested','primary_failure','cleanup_failure','native_exit','native_exit_observation'];

function m4uib_assert(bool $ok,string $code):void
{
    $GLOBALS['m4uib_assertions']=($GLOBALS['m4uib_assertions']??0)+1;
    if(!$ok){$GLOBALS['m4uib_code']=$code;throw new RuntimeException('M4UIB_ASSERTION');}
}
function m4uib_keys(array $value,array $keys):void
{
    $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);
    m4uib_assert($actual===$keys,'EXACT_FIELDS');
}
function m4uib_hex(mixed $value,int $length=64):bool
{return is_string($value)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)===1;}
function m4uib_positive(mixed $value,int $maximum=2147483647):int
{m4uib_assert(is_int($value)&&$value>0&&$value<=$maximum,'POSITIVE_NATIVE_INTEGER');return $value;}
function m4uib_canonical(mixed $value):mixed
{
    if(!is_array($value))return $value;
    if(!array_is_list($value))ksort($value,SORT_STRING);
    foreach($value as$key=>$item)$value[$key]=m4uib_canonical($item);return $value;
}
function m4uib_same(mixed $left,mixed $right):bool
{return m4uib_canonical($left)===m4uib_canonical($right);}
function m4uib_relative(string $path):void
{
    m4uib_assert($path!==''&&preg_match('~(^/|\\\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$)|//|:|/$)~',$path)!==1,'RELATIVE_CLOSED_PATH');
}
function m4uib_stat(array $stat):array
{
    $out=[];foreach(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']as$key){
        m4uib_assert(isset($stat[$key])&&is_int($stat[$key]),'ACTUAL_STAT9');$out[$key]=$stat[$key];}
    return $out;
}
/** Stable complete read. Only fixed public paths or declared source descriptors call this. */
function m4uib_file(string $path,int $maximum=67108864,bool $read=false):array
{
    clearstatcache(true,$path);$before=@lstat($path);
    m4uib_assert(is_array($before)&&!is_link($path)&&realpath($path)===$path
        &&($before['mode']&0170000)===0100000&&$before['size']>=0&&$before['size']<=$maximum,'REGULAR_COMPLETE_FILE');
    $before=m4uib_stat($before);$stream=null;$raw='';$extra=null;
    try{
        $stream=@fopen($path,'rb');m4uib_assert(is_resource($stream),'READ_OPEN');
        $open=@fstat($stream);m4uib_assert(is_array($open)&&m4uib_stat($open)===$before,'SAME_READ_HANDLE');
        $hash=hash_init('sha256');$bytes=0;
        while(!feof($stream)){$chunk=fread($stream,65536);m4uib_assert(is_string($chunk),'COMPLETE_READ');
            if($chunk===''){m4uib_assert(feof($stream),'REAL_EOF');break;}
            $bytes+=strlen($chunk);m4uib_assert($bytes<=$maximum&&$bytes<=$before['size'],'READ_BOUNDED');
            hash_update($hash,$chunk);if($read)$raw.=$chunk;unset($chunk);}
        $extra=fgetc($stream);$end=fstat($stream);
        m4uib_assert($extra===false&&feof($stream)&&$bytes===$before['size']&&is_array($end)
            &&m4uib_stat($end)===$before,'READ_EOF_STAT');
        $closing=$stream;$stream=null;m4uib_assert(fclose($closing),'NATIVE_FILE_CLOSE');unset($closing);
        clearstatcache(true,$path);$after=lstat($path);
        m4uib_assert(is_array($after)&&!is_link($path)&&realpath($path)===$path&&m4uib_stat($after)===$before,'READ_PATH_STABLE');
        return ['descriptor'=>['path'=>$path,'bytes'=>$bytes,'sha256'=>hash_final($hash)],'raw'=>$read?$raw:null,'stat'=>$before];
    }finally{
        if(is_resource($stream))fclose($stream);
        // Public/source raw only. Private credential bytes are handled solely by the unchanged metadata helper.
        unset($raw,$extra);
    }
}
function m4uib_hash(string $path):string
{return m4uib_file($path)['descriptor']['sha256'];}
function m4uib_json(string $path,int $maximum=16777216):array
{
    $file=m4uib_file($path,$maximum,true);
    try{$value=json_decode($file['raw'],true,128,JSON_THROW_ON_ERROR);
        m4uib_assert(is_array($value)&&!array_is_list($value),'PUBLIC_JSON_OBJECT');return $value;}
    finally{unset($file);}
}
function m4uib_descriptor(array $descriptor,string $fixed):array
{
    m4uib_keys($descriptor,['path','bytes','sha256']);
    m4uib_assert($descriptor['path']===$fixed&&is_int($descriptor['bytes'])&&$descriptor['bytes']>0&&m4uib_hex($descriptor['sha256']),'FIXED_DESCRIPTOR');
    $file=m4uib_file($fixed,67108864,true);
    try{m4uib_assert($file['descriptor']===$descriptor,'WHOLE_DESCRIPTOR_BYTES');
        $value=json_decode($file['raw'],true,128,JSON_THROW_ON_ERROR);
        m4uib_assert(is_array($value)&&!array_is_list($value),'DESCRIPTOR_JSON_OBJECT');return $value;}
    finally{unset($file);}
}
function m4uib_dir(string $path,int $mode=0700):array
{
    clearstatcache(true,$path);$stat=lstat($path);
    m4uib_assert(is_array($stat)&&!is_link($path)&&realpath($path)===$path&&($stat['mode']&0170000)===0040000
        &&($stat['mode']&07777)===$mode&&$stat['uid']===posix_geteuid(),'OWN_REAL_DIRECTORY');
    return m4uib_stat($stat);
}
function m4uib_bootstrap():void
{
    foreach(M4UIB_BOOTSTRAP as$relative=>$pin)foreach(['/workspace','/source']as$root){
        $actual=m4uib_file($root.'/'.$relative)['descriptor'];
        m4uib_assert($actual['bytes']===$pin['bytes']&&$actual['sha256']===$pin['sha256'],'ORIGINAL_PRIMITIVES_BYTES');}
    // Original guards dispatch only for their own actual SCRIPT_FILENAME; never rewrite that global.
    require_once '/workspace/help/audit-regressions/isolated/ms4-customer-http-normal.php';
}
function m4uib_inventory(string $root,array $extra):array
{
    $paths=$extra;
    foreach(M4UIB_ROOTS as$directory){
        m4uib_assert(is_dir($root.'/'.$directory)&&!is_link($root.'/'.$directory)&&realpath($root.'/'.$directory)===$root.'/'.$directory,'SOURCE_ROOT_REAL');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as$file){m4uib_assert(!$file->isLink(),'SOURCE_NO_LINK');if($file->isFile())$paths[]=substr($file->getPathname(),strlen($root)+1);}}
    sort($paths,SORT_STRING);m4uib_assert(count($paths)===count(array_unique($paths,SORT_STRING)),'SOURCE_UNIQUE_MEMBERS');return $paths;
}
function m4uib_full_source(array $source):void
{
    $freeze=$source['freeze'];$prepare=$freeze['ui_prepare_source_extra'];$ar=$freeze['aftersale_review_source_extra'];
    $wanted=array_values(array_unique(array_merge($ar,[
        'help/audit-regressions/isolated/ms4-ui-prepare.php','help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php',
        'help/audit-regressions/isolated/lib/ms4-ui-credentials.php','help/migrations/marketplace-ms4-order-read-menus.php']),SORT_STRING));
    sort($wanted,SORT_STRING);
    m4uib_assert(count($ar)===28&&count($wanted)===32&&$prepare===$wanted
        &&$freeze['ui_prepare_source_roots']===M4UIB_ROOTS,'EXACT_REAL_PREPARE32_MEMBERS');
    $extra=array_values(array_unique(array_merge($prepare,[M4UIB_ENTRY,M4UIB_HELPER,M4UIB_META]),SORT_STRING));sort($extra,SORT_STRING);
    m4uib_assert(count($extra)===35&&$freeze['ui_backend_source_roots']===M4UIB_ROOTS
        &&$freeze['ui_backend_source_extra']===$extra&&$source['source_files']===$freeze['ui_backend_source_files'],'OWN_BACKEND35_MEMBERS');
    $rows=$source['source_files'];m4uib_assert(is_array($rows)&&array_is_list($rows),'FULL_SOURCE_ROWS');$paths=[];$allowed=[];
    foreach($rows as$row){m4uib_keys($row,['path','bytes','sha256']);m4uib_relative($row['path']);
        m4uib_assert(is_int($row['bytes'])&&$row['bytes']>=0&&m4uib_hex($row['sha256']),'SOURCE_ROW');$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);m4uib_assert($sorted===$paths&&count($paths)===count(array_unique($paths,SORT_STRING)),'SORTED_SOURCE_ROWS');
    foreach(['/source','/workspace']as$root){
        m4uib_assert(m4uib_inventory($root,$extra)===$paths,'ACTUAL_COMPLETE_SOURCE_MEMBERS');
        foreach($rows as$row){$actual=m4uib_file($root.'/'.$row['path'])['descriptor'];
            m4uib_assert($actual['bytes']===$row['bytes']&&$actual['sha256']===$row['sha256'],'ALL_SOURCE_ACTUAL_BYTES');$allowed[$root.'/'.$row['path']]=true;}}
    foreach(get_included_files()as$path)m4uib_assert(isset($allowed[realpath($path)]),'ALL_INCLUDED_SOURCE_BOUND');
    foreach($freeze['artifact_files']as$row){m4uib_keys($row,['path','bytes','sha256']);m4uib_relative($row['path']);
        $actual=m4uib_file('/run/ms4-prep/'.$row['path'])['descriptor'];
        m4uib_assert($actual['bytes']===$row['bytes']&&$actual['sha256']===$row['sha256'],'ALL_FROZEN_ARTIFACT_BYTES');}
    $metadata=m4uib_file('/workspace/'.M4UIB_META)['descriptor'];
    m4uib_assert($metadata['bytes']===5514&&$metadata['sha256']==='d98f1886b599064337cb3aed7abde2b2df885e0d986ce9006d5fe8cff24fc59f','UNCHANGED_AUDITED_METADATA');
}
function m4uib_environment(array $wrapper,array $native,string $cid,string $scope,string $stage,array $source):array
{
    m4uib_keys($wrapper,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
    m4uib_assert($wrapper['schema']===1&&$wrapper['scope']===$scope&&$wrapper['run_id']===$source['run_id']
        &&$wrapper['source_sha']===$source['source_sha']&&$wrapper['container_id']===$cid&&$wrapper['freeze_sha256']===$source['freeze_sha256'],'OWN_ENV_IDENTITY');
    $values=$wrapper['environment'];m4uib_keys($values,M4UIB_ENV_KEYS);
    foreach(['APP_ENV'=>'testing','PHP_CACHE_DRIVER'=>'redis','PHP_MARKETPLACE_AFTERSALE_ENABLED'=>'true',
        'PHP_MARKETPLACE_AFTERSALE_PRIVATE_ROOT'=>'/var/lib/crmeb-aftersale-evidence','PHP_MARKETPLACE_ENABLED'=>'true',
        'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED'=>'true','PHP_MARKETPLACE_TRADE_ENABLED'=>'true']as$key=>$value)
        m4uib_assert($values[$key]===$value,'EXACT_EIGHT_ENV_VALUES');
    $self=$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'];
    m4uib_assert(is_string($self)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$self)===1&&(int)$self<=4294967295,'ACTUAL_SELF_MERCHANT');
    $lines=[];foreach(M4UIB_ENV_KEYS as$key){m4uib_assert(is_string($values[$key])&&getenv($key)===$values[$key],'ACTUAL_EIGHT_PROCESS_ENV');
        $lines[]=json_encode($key.'='.$values[$key],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    $raw=implode("\n",$lines)."\n";
    m4uib_keys($native,['stage','native_exit','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','private_environment_logs_omitted']);
    m4uib_assert($native['stage']===$stage&&$native['native_exit']===0&&$native['stdout_bytes']===strlen($raw)
        &&$native['stdout_sha256']===hash('sha256',$raw)&&$native['stderr_bytes']===0&&$native['stderr_sha256']===hash('sha256','')
        &&$native['private_environment_logs_omitted']===true&&$wrapper['native_projection_sha256']===$native['stdout_sha256'],'ACTUAL_SORTED_EIGHT_NATIVE_ENV_BYTES');
    ksort($values,SORT_STRING);return $values;
}
function m4uib_terminal(array $source,string $proof,string $role,array $command):array
{return m4c_entry_terminal($source,$proof,$role,$command);}
function m4uib_native(array $native,array $projection,array $argv,string $label):string
{
    m4uib_keys($native,M4UIB_NATIVE_RECEIPT_KEYS);m4uib_keys($projection,['path','bytes','sha256']);
    m4uib_assert($native['label']===$label&&$native['argv']===$argv&&is_int($native['pid'])&&$native['pid']>0
        &&$native['native_exit']===0&&$native['wait_returncode']===0&&$native['stderr_bytes']===0
        &&$native['stderr_sha256']===hash('sha256',''),'REAL_NATIVE_PID_WAIT_DUAL_STREAMS');
    $file=m4uib_file($projection['path'],2097152,true);
    m4uib_assert($file['descriptor']===$projection&&$native['stdout_bytes']===$projection['bytes']
        &&$native['stdout_sha256']===$projection['sha256'],'REAL_NATIVE_WHOLE_PROJECTION');
    return $file['raw'];
}
function m4uib_same_created(array $created,array $current):void
{
    foreach(M4UIB_IMMUTABLE as$key)m4uib_assert(m4uib_same($created[$key],$current[$key]),'ALL_NATIVE_IMMUTABLE');
    $rows=static function(array $rows):array{$out=[];foreach($rows as$row)$out[]=json_encode(m4uib_canonical($row),JSON_THROW_ON_ERROR);sort($out,SORT_STRING);return $out;};
    m4uib_assert($rows($created['mounts'])===$rows($current['mounts']),'ALL_NATIVE_MOUNT_MULTISET');
}

function m4uib_paid_names():array
{
    // Exact original Host paid_history_names33 + early migration4 + the original two seals.
    $names=array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES,M4C_PREPARE_PREREQUISITES,[
        'ms4-customer-prepare-source-binding.json','customer-prepare-environment.json','customer-prepare-environment-native.json',
        'paid-producer-created.json','paid-producer-terminal.json','paid-producer-result.json',
        'paid-producer-source-binding.json','paid-producer-environment.json','paid-producer-environment-native.json']);
    foreach(['register-paid-producer','register-paid-after-c3']as$proof)
        foreach(['-created.json','-terminal.json','-result.json','-shutdown.json']as$suffix)$names[]=$proof.$suffix;
    $names[]='driver-created.json';m4uib_assert(count($names)===33&&count(array_unique($names,SORT_STRING))===33,'ORIGINAL_PAID33');
    $names=array_merge($names,['ms4-fulfillment-migration-container-created.json','ms4-fulfillment-migration-source-binding.json',
        'ms4-aftersale-early-migration-normal.json','ms4-fulfillment-migration-terminal.json',
        'paid-producer-formal-manifest.json','paid-producer-predecessor-history.json']);
    sort($names,SORT_STRING);m4uib_assert(count($names)===39&&count(array_unique($names,SORT_STRING))===39,'ORIGINAL_PAID39');return $names;
}
function m4uib_history(array $source):array
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
    m4uib_assert($volumes===$originalNames&&$identityNames===$originalNames,'ORIGINAL_SEVEN_PREPARE_VOLUMES');
    m4uib_assert(is_array($context['initial_volumes'])&&array_is_list($context['initial_volumes'])
        &&count($context['initial_volumes'])===7,'ORIGINAL_CONTEXT_SEVEN_COMPLETE_IDENTITIES');
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
    m4uib_assert(is_array($seal['prepare_public_files'])&&array_is_list($seal['prepare_public_files'])
        &&count($seal['prepare_public_files'])===7,'REAL_SEVEN_PREPARE_DESCRIPTOR_LIST');
    foreach(M4UIB_PREPARE_NAMES as$index=>$name){
        $descriptor=$seal['prepare_public_files'][$index];m4uib_descriptor($descriptor,$base.$name);$all[$name]=$descriptor['sha256'];}
    $all['ui-prepare-context.json']=$seal['prepare_context']['sha256'];
    $all['ui-prepare-formal-manifest.json']=$seal['original_formal_manifest']['sha256'];
    $all['ui-prepare-predecessor-history.json']=$source['history']['sha256'];
    m4uib_assert(count($all)===49&&m4uib_same($all,$source['prerequisites_sha256']),'OWN_EXACT49_ACTUAL_FULL_PREREQUISITE_HASHES');

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
    $env=m4uib_environment(m4uib_json($base.'ui-prepare-environment.json'),m4uib_json($base.'ui-prepare-environment-native.json'),
        $created['id'],'MS4_UI_PREPARE_REAL_ENVIRONMENT','ui-prepare-public-environment-inspect',$source);
    m4uib_assert(m4uib_same($env,$source['environment']),'SAME_REAL_PREPARE_BACKEND_EIGHT_ENVIRONMENT');

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
    require_once '/workspace/'.M4UIB_META;
    m4uib_assert(m4uib_same(m4ui_credential_meta(),$writer),'ACTUAL_UNCHANGED_FIXED_PRIVATE_METADATA');

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
    return ['seal'=>$seal,'prepare'=>$prepared,'business'=>$business,'writer'=>$writer,'actor_ids'=>$ids,'actor_map'=>$actorMap];
}

function m4uib_volumes(array $source):void
{
    $base='/run/audit/evidence/';$proof=m4uib_descriptor($source['volumes_native'],$base.'ui-volume-native.json');
    m4uib_keys($proof,['schema','scope','run_id','source_sha','freeze_sha256','volumes']);
    m4uib_assert($proof['schema']===1&&$proof['scope']==='MS4_UI_WEB_VOLUME_IDENTITIES_NATIVE'
        &&$proof['run_id']===$source['run_id']&&$proof['source_sha']===$source['source_sha']
        &&$proof['freeze_sha256']===$source['freeze_sha256'],'REAL_WEB_VOLUME_NATIVE_PROOF');
    $suffixes=array_merge(M4UIB_ORIGINAL_VOLUMES,['ui-public','ui-comments','ui-control']);sort($suffixes,SORT_STRING);
    $names=[];foreach($suffixes as$suffix)$names[]=$source['context']['project'].'_'.$suffix;
    $actual=$source['formal']['volumes'];sort($actual,SORT_STRING);
    $ids=array_keys($source['formal']['volume_identities']);sort($ids,SORT_STRING);
    m4uib_assert($actual===$names&&$ids===$names&&is_array($proof['volumes'])&&array_is_list($proof['volumes'])
        &&count($proof['volumes'])===10,'EXACT_ORIGINAL7_PLUS_REAL_UI3_VOLUMES');
    $originalNames=[];foreach(M4UIB_ORIGINAL_VOLUMES as$suffix)$originalNames[]=$source['context']['project'].'_'.$suffix;sort($originalNames,SORT_STRING);
    m4uib_assert(is_array($source['context']['initial_volumes'])&&array_is_list($source['context']['initial_volumes'])
        &&count($source['context']['initial_volumes'])===7,'CURRENT_CONTEXT_SEVEN_COMPLETE_IDENTITIES');
    $original=[];
    foreach($source['context']['initial_volumes']as$row){
        m4uib_assert(is_array($row),'CURRENT_CONTEXT_VOLUME_OBJECT');m4uib_keys($row,['name','created_at','labels']);
        m4uib_assert(is_string($row['name'])&&in_array($row['name'],$originalNames,true)&&!isset($original[$row['name']])
            &&is_string($row['created_at'])&&$row['created_at']!==''&&is_array($row['labels']),'UNIQUE_COMPLETE_CURRENT_CONTEXT_VOLUME');
        $original[$row['name']]=$row;
    }
    $originalMapNames=array_keys($original);sort($originalMapNames,SORT_STRING);
    m4uib_assert($originalMapNames===$originalNames,'COMPLETE_CURRENT_CONTEXT_VOLUME_MAP');
    $nativeByName=[];
    $format='{"name":{{json .Name}},"created_at":{{json .CreatedAt}},"labels":{{json .Labels}}}';
    foreach($suffixes as$i=>$suffix){$row=$proof['volumes'][$i];m4uib_keys($row,['identity','projection','native']);$identity=$row['identity'];
        m4uib_keys($identity,['name','created_at','labels']);$name=$names[$i];
        m4uib_assert($identity['name']===$name&&is_string($identity['created_at'])&&$identity['created_at']!==''
            &&is_array($identity['labels'])&&$identity['labels']['tianli.audit']==='1'
            &&$identity['labels']['tianli.audit.run']===$source['run_id']
            &&$identity['labels']['com.docker.compose.project']===$source['context']['project']
            &&$identity['created_at']===$source['formal']['volume_identities'][$name],'REAL_OWNED_VOLUME_IDENTITY');
        m4uib_assert($row['projection']['path']===$base.'ui-volume-'.$suffix.'.stdout','FIXED_VOLUME_NATIVE_PROJECTION');
        $raw=m4uib_native($row['native'],$row['projection'],['docker','volume','inspect','--format',$format,$name],'ui-volume-'.$suffix);
        $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);unset($raw);
        m4uib_assert(m4uib_same($value,$identity),'ACTUAL_VOLUME_NATIVE_BYTES');
        m4uib_assert(!isset($nativeByName[$name]),'UNIQUE_ACTUAL_NATIVE_VOLUME');$nativeByName[$name]=$value;
    }
    $nativeNames=array_keys($nativeByName);sort($nativeNames,SORT_STRING);
    m4uib_assert($nativeNames===$names,'COMPLETE_TEN_ACTUAL_NATIVE_VOLUME_MAP');
    foreach($original as$name=>$row)
        m4uib_assert(m4uib_same($nativeByName[$name],$row)&&$row['created_at']===$source['formal']['volume_identities'][$name],'ORIGINAL_SEVEN_IDENTITIES_UNCHANGED');
}
function m4uib_check_mounts(array $source,array $created):void
{
    $context=$source['context'];$network=$context['initial_network'];$name=$network['Name'];
    m4uib_assert($network['Id']===$context['network_id']&&$network['Internal']===true
        &&$created['network_mode']===$name&&array_keys($created['networks'])===[$name]
        &&in_array($created['networks'][$name]['NetworkID'],['',$network['Id']],true),'ONLY_ORIGINAL_INTERNAL_BACKEND_NETWORK');
    $aliases=$created['networks'][$name]['Aliases']??[];
    m4uib_assert(in_array('audit-ms4-ui-backend',$aliases,true)&&!in_array('audit-app',$aliases,true),'OWN_BACKEND_ALIAS_NO_OLD_ACTOR_ALIAS');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],false],
        '/workspace/crmeb/public/install/crmeb.sql'=>[$context['repo_dir'].'/crmeb/public/install/crmeb.sql',false]];
    $mounts=[];$tmp=[];
    foreach($created['mounts']as$mount){
        if($mount['Type']==='tmpfs'){m4uib_assert(in_array($mount['Destination'],['/tmp','/workspace/crmeb/runtime'],true)
            &&$mount['RW']===true&&!isset($tmp[$mount['Destination']]),'ONLY_DECLARED_PRIVATE_TMPFS_MOUNTS');$tmp[$mount['Destination']]=$mount;continue;}
        $mounts[]=$mount;
    }
    m4uib_assert(count($mounts)===count($binds)+count(M4UIB_VOLUME_MOUNTS),'FULL_CLOSED_BACKEND_MOUNT_SET');
    foreach(array_merge($binds,M4UIB_VOLUME_MOUNTS)as$target=>$expected){
        $matches=array_values(array_filter($mounts,static fn(array $row):bool=>$row['Destination']===$target));
        m4uib_assert(count($matches)===1&&$matches[0]['RW']===$expected[1],'ONE_EXACT_NATIVE_MOUNT_AND_ACCESS');$mount=$matches[0];
        if(isset($binds[$target]))m4uib_assert($mount['Type']==='bind'
            &&audit_canonical_host_bind_source($mount['Source'])===audit_canonical_host_bind_source($expected[0]),'REAL_READONLY_HOST_BIND');
        else m4uib_assert($mount['Type']==='volume'&&$mount['Name']===$context['project'].'_'.$expected[0],'REAL_OWNED_VOLUME_MOUNT');
    }
    $tmpKeys=array_keys($created['tmpfs']);sort($tmpKeys,SORT_STRING);
    m4uib_assert($tmpKeys===['/tmp','/workspace/crmeb/runtime'],'EXACT_TWO_PRIVATE_TMPFS_CONFIGS');
    foreach($created['tmpfs']as$options)m4uib_assert(is_string($options)&&in_array('rw',explode(',',$options),true),'ACTUAL_RW_TMPFS_OPTIONS');
    $wanted=[];foreach($binds as$target=>$_)$wanted[$target]=false;
    foreach(M4UIB_VOLUME_MOUNTS as$target=>$value)$wanted[$target]=$value[1];
    $wanted['/tmp']=true;$wanted['/workspace/crmeb/runtime']=true;$observed=[];
    $lines=file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES);m4uib_assert(is_array($lines)&&$lines!==[],'ACTUAL_KERNEL_MOUNTINFO');
    foreach($lines as$line){$halves=explode(' - ',$line,2);$left=explode(' ',$halves[0]);$right=explode(' ',$halves[1]??'');
        $target=$left[4]??'';if(!array_key_exists($target,$wanted))continue;
        m4uib_assert(!isset($observed[$target])&&in_array($wanted[$target]?'rw':'ro',explode(',',$left[5]??''),true),'ACTUAL_KERNEL_MOUNT_ACCESS');
        if(in_array($target,['/tmp','/workspace/crmeb/runtime'],true))m4uib_assert(($right[0]??'')==='tmpfs','ACTUAL_KERNEL_PRIVATE_TMPFS');
        $observed[$target]=true;}
    $expectedKeys=array_keys($wanted);$actualKeys=array_keys($observed);sort($expectedKeys,SORT_STRING);sort($actualKeys,SORT_STRING);
    m4uib_assert($expectedKeys===$actualKeys,'ALL_NATIVE_MOUNTS_OBSERVED_IN_KERNEL');
    m4uib_dir('/tmp');m4uib_dir('/workspace/crmeb/runtime');m4uib_dir('/var/lib/crmeb-aftersale-evidence');
}
function m4uib_gateway(array $source):void
{
    $gateway=m4uib_json('/run/audit/evidence/ui-gateway-created.json');m4uib_keys($gateway,array_merge(M4UIB_NATIVE_KEYS,['run_id','source_sha','proof_name']));
    $context=$source['context'];$internal=$context['initial_network'];$frontend=$context['project'].'_frontend';
    m4uib_assert($gateway['run_id']===$source['run_id']&&$gateway['source_sha']===$source['source_sha']
        &&$gateway['proof_name']==='ui-gateway'&&$gateway['id']===$source['formal']['services']['audit-ms4-ui-gateway']['id']
        &&$gateway['image']===M4UIB_GATEWAY_IMAGE&&$gateway['user']==='101:101'&&$gateway['read_only']===true
        &&$gateway['privileged']===false&&$gateway['cap_drop']===['ALL']
        &&in_array($gateway['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true)
        &&$gateway['entrypoint']===['nginx']&&$gateway['command']===['-g','daemon off;','-c','/etc/nginx/nginx.conf']
        &&$gateway['status']==='created'&&$gateway['pid']===0&&$gateway['restart_count']===0,'REAL_SINGLE_GATEWAY_CREATED');
    foreach(['tianli.audit'=>'1','tianli.audit.run'=>$source['run_id'],'com.docker.compose.project'=>$context['project'],
        'com.docker.compose.service'=>'audit-ms4-ui-gateway']as$key=>$value)m4uib_assert($gateway['labels'][$key]===$value,'OWN_GATEWAY_LABELS');
    $networks=array_keys($gateway['networks']);sort($networks,SORT_STRING);$expected=[$internal['Name'],$frontend];sort($expected,SORT_STRING);
    m4uib_assert($networks===$expected&&$gateway['network_mode']===$internal['Name']
        &&in_array($gateway['networks'][$internal['Name']]['NetworkID'],['',$internal['Id']],true),'GATEWAY_ONLY_TWO_NETWORKS');
    $public=0;$comments=0;$config=0;$nativeCount=0;
    foreach($gateway['mounts']as$mount){
        if($mount['Type']==='tmpfs'){m4uib_assert($mount['Destination']==='/tmp'&&$mount['RW']===true,'GATEWAY_ONLY_TMPFS');continue;}
        ++$nativeCount;m4uib_assert($mount['RW']===false,'GATEWAY_ALL_NATIVE_MOUNTS_READONLY');
        if($mount['Destination']==='/srv/public'&&$mount['Type']==='volume'
            &&$mount['Name']===$context['project'].'_ui-public')++$public;
        elseif($mount['Destination']==='/srv/public/uploads/store/comment'&&$mount['Type']==='volume'
            &&$mount['Name']===$context['project'].'_ui-comments')++$comments;
        elseif($mount['Destination']==='/etc/nginx/nginx.conf'&&$mount['Type']==='bind'
            &&audit_canonical_host_bind_source($mount['Source'])===audit_canonical_host_bind_source($context['prep_dir'].'/ms4-ui-nginx.conf'))++$config;
        else m4uib_assert(false,'GATEWAY_HAS_UNDECLARED_OR_PRIVATE_MOUNT');
    }
    m4uib_assert($nativeCount===3&&$public===$comments&&$comments===$config&&$config===1,'GATEWAY_ONLY_PUBLIC_AND_COMMENT_AND_CONFIG');
    $nginx=m4uib_file('/run/ms4-prep/ms4-ui-nginx.conf')['descriptor'];
    m4uib_assert($nginx['bytes']===3854&&$nginx['sha256']==='c91161450ca87958138fff8a30e64564abee2d626d0b09e65678e4f3ff90a2fd','AUDITED_NGINX_SOURCE_BYTES');
}
function m4uib_public(array $source,bool $allowNewComments=false):void
{
    $materials=m4uib_descriptor($source['public_materials'],'/run/audit/evidence/ui-public-materials.json');
    m4uib_keys($materials,['schema','scope','run_id','source_sha','freeze_sha256','files','directories','builds']);
    m4uib_assert($materials['schema']===1&&$materials['scope']==='MS4_UI_PUBLIC_MATERIALS_COMPLETE'
        &&$materials['run_id']===$source['run_id']&&$materials['source_sha']===$source['source_sha']
        &&$materials['freeze_sha256']===$source['freeze_sha256'],'CURRENT_PUBLIC_MATERIALS_BINDING');
    m4uib_keys($materials['builds'],['h5','merchant','admin']);
    foreach(['h5','merchant','admin']as$name){
        m4uib_descriptor($materials['builds'][$name],'/run/ms4-prep/manifests/ui-'.$name.'-dist.json');
    }
    $files=[];$directories=[];
    m4uib_assert(is_array($materials['files'])&&array_is_list($materials['files'])
        &&is_array($materials['directories'])&&array_is_list($materials['directories']),'COMPLETE_PUBLIC_TREE_LISTS');
    foreach($materials['files']as$row){m4uib_keys($row,['path','bytes','sha256']);m4uib_relative($row['path']);
        m4uib_assert(!isset($files[$row['path']])&&is_int($row['bytes'])&&$row['bytes']>=0&&m4uib_hex($row['sha256']),'PUBLIC_UNIQUE_FILE_ROW');$files[$row['path']]=$row;}
    foreach($materials['directories']as$path){m4uib_relative($path);m4uib_assert(!isset($directories[$path]),'PUBLIC_UNIQUE_DIRECTORY');$directories[$path]=true;}
    foreach(['index.php','router.php','install.lock','__ms4_fixture__/product.png','__ms4_assets/h5/index.html','__ms4_assets/merchant/index.html','__ms4_assets/admin/index.html']as$path)
        m4uib_assert(isset($files[$path]),'REAL_PUBLIC_AND_THREE_DISTS_PRESENT');
    $root='/workspace/crmeb/public';m4uib_dir($root,0755);$actualFiles=[];$actualDirs=[];
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file){
        m4uib_assert(!$file->isLink(),'PUBLIC_TREE_NO_LINK');$relative=substr($file->getPathname(),strlen($root)+1);
        $comment=$relative==='uploads/store/comment'||str_starts_with($relative,'uploads/store/comment/');
        if($file->isDir()){
            m4uib_dir($file->getPathname(),0755);$actualDirs[$relative]=true;
            m4uib_assert(isset($directories[$relative])||($allowNewComments&&$comment),'PUBLIC_ONLY_DECLARED_OR_NEW_COMMENT_DIRECTORY');
        }else{
            m4uib_assert($file->isFile(),'PUBLIC_ONLY_ORDINARY_FILES');$meta=m4uib_file($file->getPathname());
            if($relative==='install/crmeb.sql'){
                $originalSql=m4uib_file('/source/crmeb/public/install/crmeb.sql');
                m4uib_assert($meta['descriptor']['bytes']===$originalSql['descriptor']['bytes']
                    &&$meta['descriptor']['sha256']===$originalSql['descriptor']['sha256']
                    &&$meta['stat']['mode']===$originalSql['stat']['mode']&&$meta['stat']['uid']===$originalSql['stat']['uid']
                    &&!is_writable($file->getPathname()),'ORIGINAL_DUAL_SQL_BYTES_MODE_AND_READONLY');
                unset($originalSql);
            }else m4uib_assert(($meta['stat']['mode']&07777)===0644&&$meta['stat']['uid']===posix_geteuid(),'PUBLIC_READABLE0644_OWNED_FILE');
            $actualFiles[$relative]=true;
            if(isset($files[$relative]))m4uib_assert($meta['descriptor']['bytes']===$files[$relative]['bytes']
                &&$meta['descriptor']['sha256']===$files[$relative]['sha256'],'ALL_ORIGINAL_PUBLIC_COMMENT_AND_STATIC_BYTES');
            else m4uib_assert($allowNewComments&&$comment,'ONLY_NEW_PUBLIC_COMMENT_FILES');
        }
    }
    foreach($files as$path=>$_)m4uib_assert(isset($actualFiles[$path]),'ALL_ORIGINAL_PUBLIC_FILES_STILL_PRESENT');
    foreach($directories as$path=>$_)m4uib_assert(isset($actualDirs[$path]),'ALL_ORIGINAL_PUBLIC_DIRECTORIES_STILL_PRESENT');
}
function m4uib_source():array
{
    m4uib_assert(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8&&getmypid()===1
        &&posix_geteuid()===0&&posix_getegid()===0&&getcwd()==='/workspace'
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')==='/workspace/'.M4UIB_ENTRY
        &&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'serve'],'OWN_NATIVE_ENTRY_AND_EXACT_SERVE_ARGV');
    m4uib_assert(getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'
        &&getenv('AUDIT_REPO_DIR')==='/workspace'&&getenv('AUDIT_RUN_DIR')==='/run/audit'
        &&getenv('MS4_UI_BACKEND_PROOF_NAME')==='ui-backend','OWN_ACTUAL_ROLE_ENVIRONMENT');
    m4uib_bootstrap();$base='/run/audit/evidence/';$context=m4uib_json('/run/audit/actual-run-context.json');
    $freeze=audit_read_immutable_source_freeze(67108864,static function(bool $ok,string $code):void{m4uib_assert($ok,'FREEZE_'.$code);});
    $freezeHash=m4uib_hash('/run/audit/preparation-source-binding.json');$run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');
    m4uib_assert(is_string($run)&&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',$run)===1&&m4uib_hex($sha,40)
        &&$context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$context['freeze_sha256']===$freezeHash
        &&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY','REAL_SAME_RUN_SOURCE_AND_FREEZE');
    $created=m4uib_json($base.'ui-backend-created.json');m4uib_keys($created,array_merge(M4UIB_NATIVE_KEYS,['run_id','source_sha','proof_name']));
    $cid=$created['id'];m4uib_assert(m4uib_hex($cid)&&gethostname()===substr($cid,0,12),'REAL_OWN_CID_HOSTNAME');
    $binding=m4uib_json($base.'ms4-ui-backend-source-binding.json');m4uib_keys($binding,M4UIB_BINDING_KEYS);
    m4uib_assert($binding['schema']===1&&$binding['scope']==='MS4_UI_BACKEND_SOURCE'&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===m4uib_hash($base.'ui-backend-created.json')
        &&$binding['entry_sha256']===m4uib_hash('/workspace/'.M4UIB_ENTRY),'OWN_CREATED_COMPLETE_BINDING');
    foreach(['requested_repo_source'=>'repo_dir','requested_run_source'=>'run_dir','requested_prep_source'=>'prep_dir']as$key=>$ctx)
        m4uib_assert($binding[$key]===$context[$ctx],'OWN_REAL_SOURCE_PATHS');
    m4uib_assert($created['run_id']===$run&&$created['source_sha']===$sha&&$created['proof_name']==='ui-backend'
        &&$created['image']===M4UIB_IMAGE&&$created['hostname']===gethostname()&&$created['user']==='0:0'
        &&$created['stop_signal']==='SIGTERM'&&$created['read_only']===true&&$created['privileged']===false
        &&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M4UIB_ENTRY,'serve']&&$created['workdir']==='/workspace'
        &&$created['cap_drop']===['ALL']&&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true)
        &&in_array($created['port_bindings'],[null,[]],true),'OWN_UNPRIVILEGED_NATIVE_CREATED_ROLE');
    foreach($created['ports']??[]as$mapping)m4uib_assert(in_array($mapping,[null,[]],true),'NO_BACKEND_HOST_PORT');
    foreach(['tianli.audit'=>'1','tianli.audit.run'=>$run,'com.docker.compose.project'=>$context['project'],
        'com.docker.compose.service'=>'audit-ms4-ui-backend']as$key=>$value)m4uib_assert($created['labels'][$key]===$value,'OWN_NATIVE_LABELS');
    $formal=m4uib_descriptor($binding['manifest'],'/run/audit/manifest.json');
    m4uib_assert($formal['run_id']===$run&&$formal['commit_sha']===$sha&&$formal['project']===$context['project']
        &&$formal['isolation']==='ISO-FULL'&&$formal['guard_mode']==='read-only'&&$formal['network_internal']===true,'CURRENT_ACTUAL_FORMAL');
    $roles=array_keys($formal['services']);sort($roles,SORT_STRING);
    m4uib_assert($roles===['audit-ms4-ui-backend','audit-ms4-ui-gateway','audit-ms5-paid-producer','audit-mysql','audit-redis']
        &&$formal['services']['audit-ms4-ui-backend']['id']===$cid&&$formal['services']['audit-ms4-ui-backend']['image_id']===M4UIB_IMAGE,'ACTUAL_ONLY_FINAL_FIVE_ROLES');
    $source=array_replace($binding,['context'=>$context,'freeze'=>$freeze,'formal'=>$formal,'created'=>$created,
        'source_binding_sha256'=>m4uib_hash($base.'ms4-ui-backend-source-binding.json'),
        'environment_sha256'=>$binding['environment']['sha256'],'environment_native_sha256'=>$binding['environment_native']['sha256']]);
    $wrapper=m4uib_descriptor($binding['environment'],$base.'ui-backend-environment.json');
    $native=m4uib_descriptor($binding['environment_native'],$base.'ui-backend-environment-native.json');
    $source['environment']=m4uib_environment($wrapper,$native,$cid,'MS4_UI_BACKEND_REAL_ENVIRONMENT','ui-backend-public-environment-inspect',$source);
    $network=m4uib_json('/run/audit/network.json');
    m4uib_assert(m4uib_same($network,$context['initial_network']),'ORIGINAL_NETWORK_PROJECTION_UNCHANGED');
    $source['network_sha256']=m4uib_hash('/run/audit/network.json');
    $source['gateway_created_sha256']=m4uib_hash($base.'ui-gateway-created.json');
    m4uib_full_source($source);m4uib_check_mounts($source,$created);m4uib_volumes($source);m4uib_gateway($source);
    $currentPath=$base.'ui-backend-current.json';$until=microtime(true)+10.0;
    while(!file_exists($currentPath)){m4uib_assert(empty($GLOBALS['m4uib_terminate_requested']),'SOURCE_WAIT_NOT_INTERRUPTED');
        m4uib_assert(microtime(true)<$until,'ACTUAL_CURRENT_NATIVE_PROOF_NOT_YET_PUBLISHED');usleep(10000);}
    $current=m4uib_json($currentPath);m4uib_keys($current,M4UIB_CURRENT_KEYS);
    m4uib_assert($current['schema']===1&&$current['scope']==='MS4_UI_BACKEND_CURRENT_NATIVE'
        &&$current['run_id']===$run&&$current['source_sha']===$sha&&$current['freeze_sha256']===$freezeHash
        &&$current['container_id']===$cid&&$current['native_created_sha256']===$source['native_created_sha256']
        &&$current['source_binding_sha256']===$source['source_binding_sha256']
        &&$current['projection']['path']===$base.'ui-backend-current.stdout','OWN_ACTUAL_POSTSTART_NATIVE_PROOF');
    $raw=m4uib_native($current['native'],$current['projection'],['docker','inspect','--type','container','--format',M4UIB_INSPECT_FORMAT,$cid],'ui-backend-current');
    $actual=json_decode($raw,true,64,JSON_THROW_ON_ERROR);unset($raw);m4uib_keys($actual,M4UIB_NATIVE_KEYS);
    m4uib_same_created($created,$actual);
    m4uib_assert($actual['status']==='running'&&is_int($actual['pid'])&&$actual['pid']>0&&$actual['restart_count']===0,'REAL_OWN_CONTAINER_RUNNING');
    m4uib_check_mounts($source,$actual);$source['native_current_sha256']=m4uib_hash($currentPath);
    $source['prepare_history']=m4uib_history($source);
    m4uib_assert(!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/public/install.lock'),'NO_MUTABLE_HOST_RUNTIME_CONFIG');
    return $source;
}
function m4uib_preserved(array $source,bool $after=false):void
{
    foreach($source['prerequisites_sha256']as$name=>$hash){
        m4uib_assert(is_string($name)&&basename($name)===$name&&m4uib_hex($hash),'FIXED_PUBLIC_PREREQUISITE_NAME');
        m4uib_assert(m4uib_hash('/run/audit/evidence/'.$name)===$hash,'ALL49_ORIGINAL_PUBLIC_INPUT_BYTES');}
    m4uib_assert(m4uib_hash('/run/audit/evidence/ms4-ui-backend-source-binding.json')===$source['source_binding_sha256']
        &&m4uib_hash('/run/audit/evidence/ui-backend-current.json')===$source['native_current_sha256']
        &&m4uib_hash('/run/audit/network.json')===$source['network_sha256']
        &&m4uib_hash('/run/audit/evidence/ui-gateway-created.json')===$source['gateway_created_sha256'],'OWN_SOURCE_AND_CURRENT_PROOFS_UNCHANGED');
    m4uib_assert(m4uib_same(m4ui_credential_meta(),$source['prepare_history']['writer']),'FIXED_CREDENTIAL_METADATA_STILL_ORIGINAL');
    m4uib_full_source($source);m4uib_public($source,$after);
}

function m4uib_private_log(string $path):array
{
    m4uib_assert(!file_exists($path)&&!is_link($path)&&umask()===0077,'FRESH_PRIVATE_LOG');
    $stream=fopen($path,'xb');m4uib_assert(is_resource($stream),'CREATE_PRIVATE_LOG');
    try{m4uib_assert(fflush($stream),'FLUSH_EMPTY_PRIVATE_LOG');$stat=fstat($stream);
        m4uib_assert(is_array($stat)&&($stat['mode']&07777)===0600&&$stat['uid']===posix_geteuid()
            &&$stat['nlink']===1&&$stat['size']===0,'PRIVATE_LOG0600_NATIVE_IDENTITY');}
    finally{fclose($stream);}
    return m4uib_file($path)['stat'];
}
function m4uib_log_identity(array $initial):void
{
    // Live child may append concurrently. Identity checks do not invent a stable current stream hash.
    foreach(M4UIB_LOGS as$path){
        clearstatcache(true,$path);$stat=lstat($path);$before=$initial[$path];
        m4uib_assert(is_array($stat)&&!is_link($path)&&is_file($path)&&realpath($path)===$path,'LIVE_PRIVATE_LOG_REGULAR');
        foreach(['dev','ino','mode','uid','gid','nlink']as$key)m4uib_assert($stat[$key]===$before[$key],'LIVE_PRIVATE_LOG_SAME_NATIVE_IDENTITY');
        m4uib_assert(($stat['mode']&07777)===0600&&$stat['nlink']===1,'LIVE_PRIVATE_LOG0600');
    }
}
function m4uib_streams(array $initial):array
{
    $out=[];foreach(M4UIB_LOGS as$path){$actual=m4uib_file($path);$before=$initial[$path];$stat=$actual['stat'];
        foreach(['dev','ino','mode','uid','gid','nlink']as$key)m4uib_assert($stat[$key]===$before[$key],'SAME_PRIVATE_LOG_NATIVE_IDENTITY');
        m4uib_assert(($stat['mode']&07777)===0600&&$stat['nlink']===1,'PRIVATE_LOGS_STAY0600');
        $out[]=$actual['descriptor'];}return $out;
}
function m4uib_publish(string $path,array $value):array
{
    m4uib_assert(str_starts_with($path,M4UIB_CONTROL.'/')&&dirname($path)===M4UIB_CONTROL
        &&!file_exists($path)&&!is_link($path)&&umask()===0077,'CREATE_ONLY_OWN_CONTROL_FILE');
    m4uib_dir(M4UIB_CONTROL);$raw=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $stream=fopen($path,'xb');m4uib_assert(is_resource($stream),'OWN_CONTROL_EXCLUSIVE_OPEN');
    try{$offset=0;while($offset<strlen($raw)){$written=fwrite($stream,substr($raw,$offset));
        m4uib_assert(is_int($written)&&$written>0,'OWN_CONTROL_FULL_WRITE');$offset+=$written;}
        m4uib_assert(fflush($stream),'OWN_CONTROL_FLUSH');$native=fstat($stream);
        m4uib_assert(is_array($native)&&($native['mode']&07777)===0600&&$native['uid']===posix_geteuid()&&$native['nlink']===1,'OWN_CONTROL_NATIVE0600');}
    finally{fclose($stream);}
    $actual=m4uib_file($path,1048576,true);
    m4uib_assert($actual['raw']===$raw,'OWN_CONTROL_FULL_READBACK');unset($raw);
    return $actual['descriptor'];
}
function m4uib_stop_request(array $source,array $ready,array $readyDescriptor):?array
{
    $path=M4UIB_CONTROL.'/ui-backend-stop-request.json';if(!file_exists($path))return null;
    m4uib_dir(M4UIB_CONTROL);$file=m4uib_file($path,16384,true);$stat=$file['stat'];
    m4uib_assert(($stat['mode']&07777)===0600&&$stat['uid']===posix_geteuid()&&$stat['nlink']===1,'OWNED_SINGLELINK_STOP_REQUEST');
    $request=json_decode($file['raw'],true,32,JSON_THROW_ON_ERROR);unset($file);
    m4uib_keys($request,['schema','scope','run_id','source_sha','freeze_sha256','container_id','ready_sha256','generation','action']);
    m4uib_assert($request['schema']===1&&$request['scope']==='MS4_UI_BACKEND_NORMAL_STOP_REQUEST'
        &&$request['run_id']===$source['run_id']&&$request['source_sha']===$source['source_sha']
        &&$request['freeze_sha256']===$source['freeze_sha256']&&$request['container_id']===$source['container_id']
        &&$request['ready_sha256']===$readyDescriptor['sha256']&&$request['generation']===$ready['generation']
        &&$request['action']==='normal-stop','SINGLE_NORMAL_STOP_SAME_READY_SOURCE_CID');
    return $request;
}
function m4uib_child_status($process):?array
{
    $status=m4c_entry_child_status($process);
    if(is_array($status)&&$status['running']===false&&!isset($GLOBALS['m4uib_first_terminal']))
        $GLOBALS['m4uib_first_terminal']=$status;
    return $status;
}
/** Finite derivative of original E:878-896. One same-group TERM; never escalate. */
function m4uib_child_stop(&$process,?array &$group,array $supervisor,bool &$sent,bool &$attempted):array
{
    if($group===null&&is_resource($process)&&proc_get_status($process)['running'])$group=audit_app_register_group($process,$supervisor);
    $registered=$group;$child=m4uib_child_status($process);$remaining=[];
    if($group!==null){$remaining=audit_app_group_members($group,$supervisor);
        if(!$attempted&&$remaining!==[]){$attempted=true;if(posix_kill(-$group['pgid'],SIGTERM))$sent=true;
            else m4c_expect(audit_app_group_members($group,$supervisor)===[],'M4C_NORMAL_TERM_FAILED');}
        $until=microtime(true)+5.0;
        do{
            $child=m4uib_child_status($process);
            while(($pid=pcntl_waitpid(-$group['pgid'],$status,WNOHANG))>0){
                $GLOBALS['m4uib_waits'][]=['pid'=>$pid,'raw_status'=>$status,
                    'exited'=>pcntl_wifexited($status),'exit_status'=>pcntl_wifexited($status)?pcntl_wexitstatus($status):null,
                    'signaled'=>pcntl_wifsignaled($status),'term_signal'=>pcntl_wifsignaled($status)?pcntl_wtermsig($status):null];
            }
            $remaining=audit_app_group_members($group,$supervisor);
            if($remaining===[]&&!audit_app_listener_open())break;usleep(20000);
        }while(microtime(true)<$until);
    }
    $closed=$remaining===[]&&!audit_app_listener_open();$nativeClose=null;
    if($closed&&is_resource($process)){$child=m4uib_child_status($process);
        m4c_expect($child!==null&&!$child['running'],'M4C_DIRECT_CHILD_RUNNING');
        $nativeClose=proc_close($process);$process=null;$group=null;}
    return ['stopped'=>$closed,'group'=>$registered,'remaining_members'=>$remaining,'listener_open'=>audit_app_listener_open(),
        'stop_signal'=>$sent?SIGTERM:null,'term_attempted'=>$attempted,'native_child'=>$child,'native_proc_close'=>$nativeClose,'normal_only'=>true];
}
function m4uib_drain():?array
{
    $state=&$GLOBALS['m4uib_state'];
    if(isset($state['child']))return $state['child'];
    if(!is_resource($state['process']??null)&&!is_array($state['group']??null))return null;
    do{
        try{$child=m4uib_child_stop($state['process'],$state['group'],$state['supervisor'],$state['sent'],$state['attempted']);}
        catch(Throwable $error){$state['cleanup']=m4uib_diagnostic($error);$child=null;usleep(20000);}
        if($child===null||!$child['stopped'])usleep(20000);
    }while($child===null||!$child['stopped']);
    $child['first_terminal']=$GLOBALS['m4uib_first_terminal']??null;$child['waits']=$GLOBALS['m4uib_waits']??[];
    $state['child']=$child; // Preserve actual native closure even if a later file proof fails.
    try{$child['streams']=m4uib_streams($state['logs']);}
    catch(Throwable $error){$state['cleanup']=m4uib_diagnostic($error);throw $error;}
    $state['child']=$child;return $child;
}
function m4uib_closed(array $child):void
{
    m4uib_keys($child,['stopped','group','remaining_members','listener_open','stop_signal','term_attempted',
        'native_child','native_proc_close','normal_only','first_terminal','waits','streams']);
    m4uib_assert($child['stopped']===true&&$child['remaining_members']===[]&&$child['listener_open']===false
        &&$child['stop_signal']===SIGTERM&&$child['term_attempted']===true&&$child['normal_only']===true
        &&is_array($child['group'])&&is_array($child['native_child'])&&$child['native_child']['running']===false
        &&$child['native_child']['stopped']===false&&$child['native_child']['stopsig']===0
        &&in_array($child['native_child']['exitcode'],[-1,0],true)&&in_array($child['native_child']['termsig'],[0,SIGTERM],true)
        &&in_array($child['native_proc_close'],[-1,0],true),'REAL_SAME_CHILD_NORMAL_NATIVE_CLOSE');
    m4uib_keys($child['group'],['leader_pid','pgid','sid','leader_starttime']);$group=$child['group'];
    m4uib_assert($group['leader_pid']>1&&$group['pgid']===$group['leader_pid']&&$group['sid']===$group['leader_pid']
        &&$child['native_child']['pid']===$group['leader_pid'],'SAME_REGISTERED_NATIVE_LEADER');
    if($child['first_terminal']!==null){$first=$child['first_terminal'];
        m4uib_assert($first['pid']===$group['leader_pid']&&$first['running']===false&&$first['stopped']===false
            &&$first['stopsig']===0&&in_array($first['exitcode'],[-1,0],true)&&in_array($first['termsig'],[0,SIGTERM],true),'FIRST_REAL_TERMINAL_PRESERVED');}
    foreach($child['waits']as$wait){
        m4uib_keys($wait,['pid','raw_status','exited','exit_status','signaled','term_signal']);
        m4uib_assert(is_int($wait['pid'])&&$wait['pid']>1&&is_int($wait['raw_status'])
            &&(($wait['exited']===true&&$wait['exit_status']===0&&$wait['signaled']===false)
                ||($wait['signaled']===true&&$wait['term_signal']===SIGTERM)),'ACTUAL_GROUP_WAIT_NORMAL_ONLY');
    }
    m4uib_assert(is_array($child['streams'])&&array_is_list($child['streams'])&&count($child['streams'])===2,'TWO_ACTUAL_PRIVATE_CHILD_STREAMS');
}
function m4uib_diagnostic(Throwable $error):array
{
    $code=$GLOBALS['m4uib_code']??$GLOBALS['m4c_failed_assertion']??'UNCLASSIFIED';
    return ['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
        'code'=>is_string($code)&&preg_match('/\A[A-Z0-9_]+\z/D',$code)===1?$code:'UNCLASSIFIED'];
}
function m4uib_result(array $source,bool $success,?array $diagnostic):array
{
    $state=$GLOBALS['m4uib_state'];$result=['schema'=>1,'scope'=>'NORMAL_MS4_UI_BACKEND_LIFECYCLE_ONLY',
        'status'=>$success?'VERIFIED_NORMAL_MS4_UI_BACKEND_CLOSED_ONLY':'INCOMPLETE_MS4_UI_BACKEND_LIFECYCLE_ONLY',
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
        'freeze_sha256'=>$source['freeze_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'environment_sha256'=>$source['environment_sha256'],
        'environment_native_sha256'=>$source['environment_native_sha256'],'prepare_history_sha256'=>$source['history']['sha256'],
        'ready_sha256'=>$state['ready']['sha256']??null,'phase'=>$state['phase'],'entry_return_code'=>$success?0:1,
        'termination'=>$success?'NORMAL_RETURN':'EXCEPTION_RETURN','native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED',
        'full_source_unchanged'=>$state['source_unchanged']??false,'child'=>$state['child']??null,
        'stop_kind'=>$state['stop_kind']??null,'diagnostic'=>$diagnostic,'three_end_login_DOM'=>'NOT RUN',
        'business_acceptance'=>'NOT RUN','full_acceptance'=>'INCOMPLETE','stopped_samples'=>'NOT RUN',
        'assertions'=>$GLOBALS['m4uib_assertions']??0];
    m4uib_keys($result,M4UIB_RESULT_KEYS);return $result;
}
function m4uib_shutdown():void
{
    $state=&$GLOBALS['m4uib_state'];if(!is_array($state['source']??null))return;
    $last=error_get_last();$fatal=is_array($last)&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true);
    if(is_resource($state['process']??null)||is_array($state['group']??null)){
        try{m4uib_drain();}catch(Throwable $error){$state['cleanup']=m4uib_diagnostic($error);}
    }
    $source=$state['source'];
    if(!$state['published']&&isset($state['control_ready'])){
        try{m4uib_publish(M4UIB_CONTROL.'/ms4-ui-backend.json',m4uib_result($source,false,$state['primary']??['code'=>'FATAL_OR_INCOMPLETE']));
            $state['published']=true;}
        catch(Throwable $ignored){$state['cleanup']=['code'=>'RESULT_NOT_PUBLISHED'];}
    }
    if(isset($state['control_ready'])){
        $value=['schema'=>1,'scope'=>'MS4_UI_BACKEND_SHUTDOWN','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
            'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],'phase'=>$state['phase'],
            'completed'=>$state['completed'],'result_published'=>$state['published'],'fatal'=>$fatal,
            'termination_requested'=>($GLOBALS['m4uib_terminate_requested']??false)===true,
            'primary'=>$state['primary'],'cleanup'=>$state['cleanup'],'child'=>$state['child'],
            'source_unchanged'=>$state['source_unchanged']];
        m4uib_keys($value,M4UIB_SHUTDOWN_KEYS);
        try{m4uib_publish(M4UIB_CONTROL.'/ui-backend-shutdown.json',$value);}
        catch(Throwable $ignored){fwrite(STDERR,"UI backend shutdown unavailable; Host native observation required\n");}
    }
}
function m4uib_serve():int
{
    $state=&$GLOBALS['m4uib_state'];$state['phase']='source';$source=m4uib_source();$state['source']=$source;
    m4uib_assert(!isset($GLOBALS['m4c_application'])&&!audit_app_listener_open(),'PARENT_NO_APP_OR_EXISTING_LISTENER');
    m4uib_public($source,false);m4uib_dir(M4UIB_CONTROL);
    m4uib_assert(scandir(M4UIB_CONTROL)===['.','..'],'FRESH_OWN_EMPTY_CONTROL_VOLUME');$state['control_ready']=true;
    foreach(M4UIB_LOGS as$path)$state['logs'][$path]=m4uib_private_log($path);
    $config=audit_config();m4uib_assert($config['run_id']===$source['run_id']&&$config['database']['username']==='audit_app','REAL_CHILD_PRIVATE_CONFIG_RUN');
    unset($config);$state['config_hash']=m4uib_hash('/run/audit-app/config.json');
    $supervisor=audit_app_proc_stat(getmypid());m4uib_assert(is_array($supervisor)&&$supervisor['pid']===1,'REAL_PID1_SUPERVISOR');
    $state['supervisor']=$supervisor;$failure=null;
    try{
        $state['phase']='child_start';
        m4uib_assert(empty($GLOBALS['m4uib_terminate_requested'])&&$state['process']===null&&$state['group']===null,'ONE_ACTUAL_CHILD_START');
        $environment=m4c_entry_child_environment($source);$prior=umask(0022);
        try{
            m4uib_assert($prior===0077,'PARENT_PRIVATE_UMASK_BEFORE_PROC_OPEN');
            $state['process']=proc_open(['setsid','php','think','run','-H','0.0.0.0','-p','8000'],
                [['file','/dev/null','r'],['file',M4UIB_LOGS[0],'a'],['file',M4UIB_LOGS[1],'a']],
                $pipes,'/workspace/crmeb',$environment);
        }finally{umask(0077);unset($environment);}
        m4uib_assert(is_resource($state['process'])&&umask()===0077,'ACTUAL_HTTP_CHILD_AND_PARENT_UMASK_RESTORED');
        $state['group']=audit_app_register_group($state['process'],$supervisor);$until=microtime(true)+5.0;
        do{$facts=audit_app_process_observation($state['group'],$supervisor);
            m4uib_assert(m4uib_child_status($state['process'])['running']===true,'SAME_HTTP_CHILD_RUNNING');
            if($facts['members']!==[]&&$facts['listener_open'])break;usleep(10000);
        }while(microtime(true)<$until);
        m4uib_assert($facts['members']!==[]&&$facts['listener_open'],'ACTUAL_REGISTERED_GROUP_OWNS_LISTENER');
        m4uib_log_identity($state['logs']);m4uib_preserved($source,false);
        $state['phase']='ready';
        $ready=['schema'=>1,'scope'=>'MS4_UI_BACKEND_READY','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
            'freeze_sha256'=>$source['freeze_sha256'],'container_id'=>$source['container_id'],
            'native_created_sha256'=>$source['native_created_sha256'],'source_binding_sha256'=>$source['source_binding_sha256'],
            'prepare_history_sha256'=>$source['history']['sha256'],'generation'=>bin2hex(random_bytes(24)),
            'supervisor'=>$supervisor,'group'=>$state['group'],'members'=>$facts['members'],'listener_open'=>$facts['listener_open'],
            'actor_ids'=>$source['prepare_history']['actor_ids'],'writer'=>$source['prepare_history']['writer'],'full_source_verified'=>true];
        $state['ready']=m4uib_publish(M4UIB_CONTROL.'/ui-backend-ready.json',$ready);
        echo json_encode(['scope'=>'MS4_UI_BACKEND_READY','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
            'container_id'=>$source['container_id'],'ready_sha256'=>$state['ready']['sha256']],JSON_THROW_ON_ERROR)."\n";
        while(true){
            if(($GLOBALS['m4uib_terminate_requested']??false)===true){$state['stop_kind']='SIGTERM';break;}
            $request=m4uib_stop_request($source,$ready,$state['ready']);
            if($request!==null){$state['stop_kind']='normal-stop-request';break;}
            $facts=audit_app_process_observation($state['group'],$supervisor);$status=m4uib_child_status($state['process']);
            m4uib_assert(is_array($status)&&$status['running']===true&&$facts['members']!==[]&&$facts['listener_open'],'SAME_GENERATION_GROUP_AND_LISTENER_REMAIN_LIVE');
            usleep(50000);
        }
    }catch(Throwable $error){$failure=$error;$state['primary']=m4uib_diagnostic($error);}
    finally{$state['phase']='child_close';if(is_resource($state['process'])||$state['group']!==null)m4uib_drain();}
    if($failure!==null)throw $failure;
    m4uib_assert($state['cleanup']===null&&is_array($state['child'])&&isset($state['ready']),'NO_CLEANUP_FAILURE_OR_UNSTARTED_SUCCESS');
    m4uib_closed($state['child']);$state['phase']='final_source';m4uib_preserved($source,true);
    m4uib_assert(m4uib_hash('/run/audit-app/config.json')===$state['config_hash'],'ORIGINAL_PRIVATE_APP_CONFIG_UNCHANGED');
    $after=m4uib_source();m4uib_assert(m4uib_same($after,$source),'COMPLETE_SOURCE_NATIVE_ENV_HISTORY_IDENTITY_UNCHANGED');
    m4uib_assert(!isset($GLOBALS['m4c_application'])&&!audit_app_listener_open(),'PARENT_REMAINS_APP_FREE_AND_LISTENER_CLOSED');
    $state['source_unchanged']=true;
    m4uib_publish(M4UIB_CONTROL.'/ms4-ui-backend.json',m4uib_result($source,true,null));
    $state['published']=true;$state['completed']=true;
    echo json_encode(['scope'=>'NORMAL_MS4_UI_BACKEND_LIFECYCLE_ONLY','status'=>'VERIFIED_NORMAL_MS4_UI_BACKEND_CLOSED_ONLY',
        'entry_return_code'=>0,'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";
    return 0;
}

/** Explicit independently gated same-CID observation. No App, HTTP or filesystem producer. */
function m4uib_observe_source():array
{
    m4uib_assert(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8&&getmypid()>1
        &&posix_geteuid()===0&&posix_getegid()===0&&getcwd()==='/workspace'
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')==='/workspace/'.M4UIB_ENTRY
        &&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'observe'],'OWN_EXEC_OBSERVER_NATIVE_ENTRY_AND_EXACT_ARGV');
    m4uib_assert(getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'
        &&getenv('AUDIT_REPO_DIR')==='/workspace'&&getenv('AUDIT_RUN_DIR')==='/run/audit'
        &&getenv('MS4_UI_BACKEND_PROOF_NAME')==='ui-backend','OWN_ACTUAL_ROLE_ENVIRONMENT');
    m4uib_bootstrap();$base='/run/audit/evidence/';$context=m4uib_json('/run/audit/actual-run-context.json');
    $freeze=audit_read_immutable_source_freeze(67108864,static function(bool $ok,string $code):void{m4uib_assert($ok,'FREEZE_'.$code);});
    $freezeHash=m4uib_hash('/run/audit/preparation-source-binding.json');$run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');
    m4uib_assert(is_string($run)&&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',$run)===1&&m4uib_hex($sha,40)
        &&$context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$context['freeze_sha256']===$freezeHash
        &&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY','REAL_SAME_RUN_SOURCE_AND_FREEZE');
    $created=m4uib_json($base.'ui-backend-created.json');m4uib_keys($created,array_merge(M4UIB_NATIVE_KEYS,['run_id','source_sha','proof_name']));
    $cid=$created['id'];m4uib_assert(m4uib_hex($cid)&&gethostname()===substr($cid,0,12),'REAL_OWN_CID_HOSTNAME');
    $binding=m4uib_json($base.'ms4-ui-backend-source-binding.json');m4uib_keys($binding,M4UIB_BINDING_KEYS);
    m4uib_assert($binding['schema']===1&&$binding['scope']==='MS4_UI_BACKEND_SOURCE'&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===m4uib_hash($base.'ui-backend-created.json')
        &&$binding['entry_sha256']===m4uib_hash('/workspace/'.M4UIB_ENTRY),'OWN_CREATED_COMPLETE_BINDING');
    foreach(['requested_repo_source'=>'repo_dir','requested_run_source'=>'run_dir','requested_prep_source'=>'prep_dir']as$key=>$ctx)
        m4uib_assert($binding[$key]===$context[$ctx],'OWN_REAL_SOURCE_PATHS');
    m4uib_assert($created['run_id']===$run&&$created['source_sha']===$sha&&$created['proof_name']==='ui-backend'
        &&$created['image']===M4UIB_IMAGE&&$created['hostname']===gethostname()&&$created['user']==='0:0'
        &&$created['stop_signal']==='SIGTERM'&&$created['read_only']===true&&$created['privileged']===false
        &&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['entrypoint']===['php']&&$created['command']===[M4UIB_ENTRY,'serve']&&$created['workdir']==='/workspace'
        &&$created['cap_drop']===['ALL']&&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true)
        &&in_array($created['port_bindings'],[null,[]],true),'OWN_UNPRIVILEGED_NATIVE_CREATED_ROLE');
    foreach($created['ports']??[]as$mapping)m4uib_assert(in_array($mapping,[null,[]],true),'NO_BACKEND_HOST_PORT');
    foreach(['tianli.audit'=>'1','tianli.audit.run'=>$run,'com.docker.compose.project'=>$context['project'],
        'com.docker.compose.service'=>'audit-ms4-ui-backend']as$key=>$value)m4uib_assert($created['labels'][$key]===$value,'OWN_NATIVE_LABELS');
    $formal=m4uib_descriptor($binding['manifest'],'/run/audit/manifest.json');
    m4uib_assert($formal['run_id']===$run&&$formal['commit_sha']===$sha&&$formal['project']===$context['project']
        &&$formal['isolation']==='ISO-FULL'&&$formal['guard_mode']==='read-only'&&$formal['network_internal']===true,'CURRENT_ACTUAL_FORMAL');
    $roles=array_keys($formal['services']);sort($roles,SORT_STRING);
    m4uib_assert($roles===['audit-ms4-ui-backend','audit-ms4-ui-gateway','audit-ms5-paid-producer','audit-mysql','audit-redis']
        &&$formal['services']['audit-ms4-ui-backend']['id']===$cid&&$formal['services']['audit-ms4-ui-backend']['image_id']===M4UIB_IMAGE,'ACTUAL_ONLY_FINAL_FIVE_ROLES');
    $source=array_replace($binding,['context'=>$context,'freeze'=>$freeze,'formal'=>$formal,'created'=>$created,
        'source_binding_sha256'=>m4uib_hash($base.'ms4-ui-backend-source-binding.json'),
        'environment_sha256'=>$binding['environment']['sha256'],'environment_native_sha256'=>$binding['environment_native']['sha256']]);
    $wrapper=m4uib_descriptor($binding['environment'],$base.'ui-backend-environment.json');
    $native=m4uib_descriptor($binding['environment_native'],$base.'ui-backend-environment-native.json');
    $source['environment']=m4uib_environment($wrapper,$native,$cid,'MS4_UI_BACKEND_REAL_ENVIRONMENT','ui-backend-public-environment-inspect',$source);
    $network=m4uib_json('/run/audit/network.json');
    m4uib_assert(m4uib_same($network,$context['initial_network']),'ORIGINAL_NETWORK_PROJECTION_UNCHANGED');
    $source['network_sha256']=m4uib_hash('/run/audit/network.json');
    $source['gateway_created_sha256']=m4uib_hash($base.'ui-gateway-created.json');
    m4uib_full_source($source);m4uib_check_mounts($source,$created);m4uib_volumes($source);m4uib_gateway($source);
    $currentPath=$base.'ui-backend-current.json';$until=microtime(true)+10.0;
    while(!file_exists($currentPath)){m4uib_assert(empty($GLOBALS['m4uib_terminate_requested']),'SOURCE_WAIT_NOT_INTERRUPTED');
        m4uib_assert(microtime(true)<$until,'ACTUAL_CURRENT_NATIVE_PROOF_NOT_YET_PUBLISHED');usleep(10000);}
    $current=m4uib_json($currentPath);m4uib_keys($current,M4UIB_CURRENT_KEYS);
    m4uib_assert($current['schema']===1&&$current['scope']==='MS4_UI_BACKEND_CURRENT_NATIVE'
        &&$current['run_id']===$run&&$current['source_sha']===$sha&&$current['freeze_sha256']===$freezeHash
        &&$current['container_id']===$cid&&$current['native_created_sha256']===$source['native_created_sha256']
        &&$current['source_binding_sha256']===$source['source_binding_sha256']
        &&$current['projection']['path']===$base.'ui-backend-current.stdout','OWN_ACTUAL_POSTSTART_NATIVE_PROOF');
    $raw=m4uib_native($current['native'],$current['projection'],['docker','inspect','--type','container','--format',M4UIB_INSPECT_FORMAT,$cid],'ui-backend-current');
    $actual=json_decode($raw,true,64,JSON_THROW_ON_ERROR);unset($raw);m4uib_keys($actual,M4UIB_NATIVE_KEYS);
    m4uib_same_created($created,$actual);
    m4uib_assert($actual['status']==='running'&&is_int($actual['pid'])&&$actual['pid']>0&&$actual['restart_count']===0,'REAL_OWN_CONTAINER_RUNNING');
    m4uib_check_mounts($source,$actual);$source['native_current_sha256']=m4uib_hash($currentPath);
    $source['prepare_history']=m4uib_history($source);
    m4uib_assert(!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/public/install.lock'),'NO_MUTABLE_HOST_RUNTIME_CONFIG');
    return $source;
}

function m4uwo_gate():array
{
    $s=m4uib_observe_source();
    m4uib_assert(explode("\0",rtrim(file_get_contents('/proc/self/cmdline'),"\0"))===['php','/workspace/'.M4UIB_ENTRY,'observe'],'OBSERVER_REAL_KERNEL_ARGV');
    m4uib_assert(!isset($GLOBALS['m4c_application'])&&!isset($GLOBALS['m4c_app_started']),'OBSERVER_NO_APP');
    $readyFile=m4uib_file(M4UIB_CONTROL.'/ui-backend-ready.json',65536,true);
    $r=json_decode($readyFile['raw'],true,64,JSON_THROW_ON_ERROR);
    m4uib_keys($r,['schema','scope','run_id','source_sha','freeze_sha256','container_id','native_created_sha256','source_binding_sha256','prepare_history_sha256','generation','supervisor','group','members','listener_open','actor_ids','writer','full_source_verified']);
    m4uib_assert($r['schema']===1&&$r['scope']==='MS4_UI_BACKEND_READY'&&$r['run_id']===$s['run_id']&&$r['source_sha']===$s['source_sha']
        &&$r['freeze_sha256']===$s['freeze_sha256']&&$r['container_id']===$s['container_id']
        &&$r['source_binding_sha256']===$s['source_binding_sha256']&&$r['native_created_sha256']===$s['native_created_sha256']
        &&$r['prepare_history_sha256']===$s['history']['sha256']&&$r['actor_ids']===$s['prepare_history']['actor_ids']
        &&$r['writer']===$s['prepare_history']['writer']&&$r['full_source_verified']===true&&$r['listener_open']===true
        &&is_string($r['generation'])&&preg_match('/\A[a-f0-9]{48}\z/D',$r['generation'])===1,'OBSERVER_ORIGINAL_READY17');
    $parent=audit_app_proc_stat(1);$own=audit_app_proc_stat(getmypid());
    m4uib_assert(is_array($parent)&&is_array($own)&&$own['pid']>1&&$own['state']!=='Z'
        &&$parent['pid']===$r['supervisor']['pid']&&$parent['pid']===1&&$parent['starttime']===$r['supervisor']['starttime']
        &&$parent['pgid']===$r['supervisor']['pgid']&&$parent['sid']===$r['supervisor']['sid'],'OBSERVER_SAME_LIVE_PID1_SUPERVISOR');
    $live=audit_app_process_observation($r['group'],$r['supervisor']);
    m4uib_assert($live['listener_open']===true&&$live['members']!==[]&&!file_exists(M4UIB_CONTROL.'/ui-backend-stop-request.json'),'OBSERVER_SAME_LIVE_CHILD_BEFORE_STOP');
    foreach($live['members']as$m)m4uib_assert($m['pid']!==getmypid(),'OBSERVER_NOT_HTTP_GROUP_MEMBER');
    $s['observer_ready']=$r;$s['observer_ready_sha256']=$readyFile['descriptor']['sha256'];
    // Definitions-only import. It does not dispatch any prepare/AR/paid driver for this SCRIPT_FILENAME.
    require_once '/workspace/help/audit-regressions/isolated/ms4-ui-prepare.php';
    m4uib_full_source($s);m4uib_preserved($s,true);
    return $s;
}
function m4uwo_bind(array $binding,array $s):void
{
    m4uib_keys($binding,['run_id','source_sha','freeze_sha256','context_sha256','backend_cid','gateway_cid','ready_sha256','origin','source_closure']);
    $base='/run/audit/evidence/';$handoff=m4uib_json($base.'ui-private-caller-handoff.json');
    $gateway=m4uib_json($base.'ui-gateway-created.json');$c=$binding['source_closure'];
    m4uib_assert($binding['run_id']===$s['run_id']&&$binding['source_sha']===$s['source_sha']&&$binding['freeze_sha256']===$s['freeze_sha256']
        &&$binding['context_sha256']===m4uib_hash('/run/audit/actual-run-context.json')&&$binding['backend_cid']===$s['container_id']
        &&$binding['gateway_cid']===$gateway['id']&&$binding['gateway_cid']!==$s['container_id']
        &&$binding['ready_sha256']===$s['observer_ready_sha256']&&$binding['origin']===$s['freeze']['ui_web_origin'],'OBSERVER_EXACT_CURRENT_BINDING9');
    m4uib_keys($c,['context','freeze','artifacts','reader','caller','browser_close']);
    $aux=$c;unset($aux['browser_close']);
    m4uib_assert($handoff['schema']===1&&$handoff['scope']==='MS4_UI_PRIVATE_CALLER_HANDOFF_ONLY'
        &&$handoff['run_id']===$s['run_id']&&$handoff['source_sha']===$s['source_sha']&&$handoff['freeze_sha256']===$s['freeze_sha256']
        &&$handoff['container_id']===$s['container_id']&&$handoff['private_reader_returned_three_roles']===true
        &&m4uib_same($aux,$handoff['source_closure']),'OBSERVER_ACTUAL_HANDOFF_CLOSURE5');
    foreach(['reader','caller','browser_close']as$k){m4uib_keys($c[$k],['bytes','sha256']);m4uib_assert(is_int($c[$k]['bytes'])&&$c[$k]['bytes']>0&&m4uib_hex($c[$k]['sha256']),'OBSERVER_PUBLIC_AUX_SOURCE_DESCRIPTOR');}
    // The container cannot inspect external JS Source: Root must independently pin its finished auxiliary closure.
    m4uib_assert($c['context']===['bytes'=>m4uib_file('/run/audit/actual-run-context.json')['descriptor']['bytes'],'sha256'=>$binding['context_sha256']]
        &&$c['freeze']===['bytes'=>m4uib_file('/run/audit/preparation-source-binding.json')['descriptor']['bytes'],'sha256'=>$binding['freeze_sha256']],'OBSERVER_CURRENT_CONTEXT_FREEZE_BYTES');
    $actual=[];foreach($s['freeze']['artifact_files']as$r)$actual[$r['path']]=['bytes'=>$r['bytes'],'sha256'=>$r['sha256']];
    m4uib_assert($c['artifacts']===$actual&&count($actual)===17,'OBSERVER_ACTUAL_ORDERED_SOURCE17');
    m4uib_assert(!file_exists('/run/audit/evidence/ui-business-window.json'),'OBSERVER_NO_EXISTING_BUSINESS_SUCCESS');
}
function m4uwo_frame():array
{
    $raw=fgets(STDIN,1048578);m4uib_assert(is_string($raw)&&strlen($raw)<=1048576&&str_ends_with($raw,"\n")&&!str_contains($raw,"\0"),'OBSERVER_BOUNDED_COMPLETE_STDIN_FRAME');
    $v=json_decode($raw,false,128,JSON_THROW_ON_ERROR);unset($raw);m4uib_assert($v instanceof stdClass,'OBSERVER_OBJECT_FRAME');
    $a=get_object_vars($v);m4uib_keys($a,['schema','binding','sequence','command','step','wire_public','private']);
    m4uib_assert($a['schema']===1&&is_int($a['sequence'])&&$a['sequence']>=0&&is_int($a['step'])&&$a['step']>=0
        &&in_array($a['command'],['BEGIN','STEP_BEGIN','STEP_VERIFY','FINISH'],true),'OBSERVER_ONLY_SERIAL_ENVELOPE');
    m4uib_assert($a['private']===null||$a['private'] instanceof stdClass,'OBSERVER_OPTIONAL_RAM_ONLY_LOGIN_OBSERVATION');
    m4uib_assert($a['binding'] instanceof stdClass,'OBSERVER_BINDING_OBJECT');$a['binding']=m4uwo_plain($a['binding']);return $a;
}
function m4uwo_send(array $frame,array $receipt):void
{
    $out=['schema'=>1,'scope'=>'MS4_UI_OBSERVER_SAFE_FRAME','binding'=>$frame['binding'],'sequence'=>$frame['sequence'],
        'command'=>$frame['command'],'step'=>$frame['step'],'receipt'=>$receipt];
    $raw=m4uwo_json($out)."\n";m4uib_assert(strlen($raw)<=16777216,'OBSERVER_BOUNDED_SAFE_RESPONSE');
    $GLOBALS['m4uwo_stdout_bytes']=($GLOBALS['m4uwo_stdout_bytes']??0)+strlen($raw);
    m4uib_assert($GLOBALS['m4uwo_stdout_bytes']<=268435456,'OBSERVER_BOUNDED_COMPLETE_STDOUT_STREAM');
    m4uib_assert(fwrite(STDOUT,$raw)===strlen($raw)&&fflush(STDOUT),'OBSERVER_FULL_PUBLIC_FRAME_WRITE');unset($raw);
}
function m4uib_observe():int
{
    $s=m4uwo_gate();$frame=m4uwo_frame();m4uib_assert($frame['command']==='BEGIN'&&$frame['sequence']===0&&$frame['step']===0
        &&$frame['wire_public']===null,'OBSERVER_SINGLE_BEGIN');m4uwo_bind($frame['binding'],$s);$binding=$frame['binding'];
    m4uib_assert($frame['private']===null,'OBSERVER_BEGIN_NO_SECRET_PAYLOAD');$s['observer_gateway_current_sha256']=m4uib_hash('/run/audit/evidence/ui-gateway-current.json');
    m4uib_assert(date_default_timezone_set('Asia/Shanghai'),'OBSERVER_SAME_SOURCE_APP_DEFAULT_TIMEZONE_NO_APP_BOOT');
    $db=audit_db();m4uib_assert(!$db->inTransaction(),'OBSERVER_NATIVE_PDO_NO_TRANSACTION');m5pc_observer_configure($db);
    $baseline=m4uwo_snapshot($db);m4uwo_script_empty($baseline);$checked=m4uwo_snapshot($db);m4uwo_same($baseline,$checked);unset($checked);
    $business=m4uib_json('/run/audit/evidence/ms4-ui-prepare.json')['business'];
    m4uib_assert(m4upo_descriptor($baseline)['database_sha256']===$business['window_final']['database_sha256']
        &&m4upo_descriptor($baseline)['redis_sha256']===$business['window_final']['redis_sha256'],'OBSERVER_GENUINE_PREPARED_FULL_DATABASE_REDIS_BASELINE');
    $saved=m5pcp_saved($db,$business['paid_order_id']);$original=m4fn_source_oracle($saved);
    m4uib_assert($original['fingerprint']===$business['source_fingerprint']&&$saved['order']['order_id']===$business['paid_order_no'],'OBSERVER_ACTUAL_SAVED_ORDER_MODEL');
    $state=['source'=>$original,'saved'=>$saved,'prepare'=>$business,'binding'=>$binding,'index'=>0,'sessions'=>[],
        'startup_original'=>m4uws_original($baseline),
        'runtime_log_format'=>'PHP82_BUILTIN_UTC_ACCESS_V1'];
    $first=m4uwo_ledger($baseline,$binding,0,'baseline');m4uwo_send($frame,['baseline'=>$first]);$last=$baseline;unset($baseline,$saved,$original,$first);
    try{
        foreach(m4uwo_steps()as$offset=>$name){$i=$offset+1;$frame=m4uwo_frame();
            m4uib_assert($frame['command']==='STEP_BEGIN'&&$frame['sequence']===2*$i-1&&$frame['step']===$i
                &&$frame['wire_public']===null&&$frame['private']===null&&m4uib_same($frame['binding'],$binding),'OBSERVER_EXACT_NEXT_BEGIN');
            // An unimplemented independent oracle blocks BEFORE Root may perform this UI action.
            m4uib_assert(m4uwo_case_supported($name),'OBSERVER_CASE_NOT_IMPLEMENTED_'.$i);
            $phaseBegin=(int)floor(microtime(true)*1000);m4uib_preserved($s,true);
            $now=m4uwo_snapshot($db);m4uwo_same($last,$now);unset($last);$before=$now;unset($now);
            m4uwo_send($frame,['before'=>m4uwo_ledger($before,$binding,$i,'before')]);$frame=m4uwo_frame();
            m4uib_assert($frame['command']==='STEP_VERIFY'&&$frame['sequence']===2*$i&&$frame['step']===$i
                &&m4uib_same($frame['binding'],$binding),'OBSERVER_EXACT_MATCHING_VERIFY');
            $phaseEnd=(int)ceil(microtime(true)*1000);$event=m4uwo_wire($name,$frame['wire_public'],$state);
            m4uib_assert($event['event']['started_at_ms']>=$phaseBegin&&$event['event']['finished_at_ms']<=$phaseEnd,'OBSERVER_BROWSER_TIME_INSIDE_REAL_NATIVE_WINDOW');
            $event['private']=$frame['private'];$frame['private']=null;$state['sequence_barrier']=m4uwo_sequence_barrier($name,$event);$after=m4uwo_snapshot($db);
            $expected=m4uwo_verify_case($name,$before,$after,$event,$state,$s);unset($event['private']);m4uib_preserved($s,true);
            $again=m4uwo_snapshot($db);m4uwo_same($after,$again);
            $actual=m4uwo_ledger($after,$binding,$i,'after');$verified=m4uwo_ledger($again,$binding,$i,'verified');
            m4uwo_send($frame,['after'=>$actual,'verified_after'=>$verified,'expected_delta'=>$expected,
                'oracle'=>['source'=>m4uib_file('/workspace/help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php')['descriptor'],
                    'case_name'=>$name,'expected'=>$expected,'actual'=>$actual,'readback'=>$verified]]);
            $last=$again;$state['index']=$i;unset($before,$after,$again,$actual,$verified,$expected,$event);
        }
        $frame=m4uwo_frame();$n=count(m4uwo_steps());m4uib_assert($frame['command']==='FINISH'&&$frame['sequence']===2*$n+1&&$frame['step']===$n+1
            &&$frame['wire_public']===null&&$frame['private']===null&&m4uib_same($frame['binding'],$binding)&&$state['index']===$n,'OBSERVER_ONE_COMPLETE_FINISH');
        $final=m4uwo_snapshot($db);m4uwo_same($last,$final);m4uwo_gateway_ip($s);m4uwo_finish_assert($state);m4uib_preserved($s,true);
        $again=m4uwo_gate();m4uib_assert($again['observer_ready_sha256']===$s['observer_ready_sha256']&&$again['observer_ready']['generation']===$s['observer_ready']['generation'],'OBSERVER_SAME_FINAL_READY_SOURCE_SUPERVISOR');
        m4uwo_send($frame,['final'=>m4uwo_ledger($final,$binding,$n+1,'final'),'preservation'=>m4uwo_preservation($s,$binding)]);
        $tail=fread(STDIN,1);m4uib_assert($tail===''&&feof(STDIN),'OBSERVER_FINAL_EXACT_STDIN_EOF');
        m4uib_assert(!isset($GLOBALS['m4c_application'])&&!isset($GLOBALS['m4c_app_started']),'OBSERVER_FINISH_NO_APP');return 0;
    }finally{unset($last,$before,$after,$again,$final,$state,$frame,$event,$db,$s,$binding);}
}
