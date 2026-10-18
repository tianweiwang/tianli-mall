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
        'binding_scope'=>'MS4_CUSTOMER_PREPARE_MS5_SOURCE','environment'=>'customer-prepare-environment.json',
        'result'=>'ms4-customer-prepare.json','scope'=>'CUSTOMER_HTTP_FIXTURE_WITH_MS5_PREREQUISITES_PREPARATION_ONLY',
        'status'=>'VERIFIED_CUSTOMER_HTTP_FIXTURE_WITH_MS5_PREREQUISITES_PREPARED_ONLY'],
    'verify'=>['role'=>'audit-ms4-customer-http','proof'=>'customer','binding'=>'ms4-customer-http-source-binding.json',
        'binding_scope'=>'MS4_CUSTOMER_HTTP_MS5_SOURCE','environment'=>'customer-environment.json',
        'result'=>'ms4-customer-http.json','scope'=>'NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY',
        'status'=>'VERIFIED_NORMAL_CUSTOMER_HTTP_PERSISTENT_WITH_MS5_PREREQUISITES_ONLY']
];
const M4C_COMMON_PREREQUISITES=['driver-terminal.json','initializer-completed-terminal.json','initializer-created.json',
    'initializer-result.json','ms5-payment-migration-container-created.json','ms5-payment-migration-normal.json',
    'ms5-payment-migration-formal-manifest.json','ms5-payment-migration-source-binding.json'];
const M4C_FILES_PREREQUISITES=['files-bootstrap-created.json','files-bootstrap-terminal.json',
    'files-bootstrap-result.json','files-bootstrap-shutdown.json'];
const M4C_PREPARE_PREREQUISITES=['customer-prepare-created.json','customer-prepare-terminal.json','ms4-customer-prepare.json'];
const M4C_MIGRATION_SOURCES=['crmeb/public/install/crmeb.sql','help/audit-regressions/isolated/lib/guard.php',
    'help/audit-regressions/isolated/lib/harness.php','help/audit-regressions/isolated/ms5-payment-migration.php',
    'help/migrations/marketplace-ms1.php','help/migrations/marketplace-ms2.php','help/migrations/marketplace-ms4.md',
    'help/migrations/marketplace-ms4.php','help/migrations/marketplace-ms5-payment.md','help/migrations/marketplace-ms5-payment.php'];

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
function m4c_entry_terminal(array $source,string $proof,string $role,array $command,
    ?string $createdFile=null,?string $terminalFile=null): array
{
    $base='/run/audit/evidence/';$created=m4c_entry_read($base.($createdFile??$proof.'-created.json'));
    $terminal=m4c_entry_read($base.($terminalFile??$proof.'-terminal.json'));
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
function m4c_entry_hash(string $path): string
{
    clearstatcache(true,$path);m4c_expect(is_file($path)&&!is_link($path),'M4C_PREREQUISITE_REGULAR_ORIGINAL');
    $hash=hash_file('sha256',$path);m4c_expect(is_string($hash),'M4C_PREREQUISITE_COMPLETE_HASH');return $hash;
}
function m4c_entry_same(mixed $actual,mixed $expected): void
{
    if(!is_array($expected)){m4c_expect($actual===$expected,'M4C_PREREQUISITE_EXACT_NATIVE_TYPE');return;}
    m4c_expect(is_array($actual)&&array_is_list($actual)===array_is_list($expected),'M4C_PREREQUISITE_NATIVE_ARRAY_KIND');
    m4c_entry_keys($actual,array_keys($expected));foreach($expected as$key=>$value)m4c_entry_same($actual[$key],$value);
}
function m4c_entry_prerequisite_names(string $mode): array
{
    m4c_expect(isset(M4C_MODE[$mode]),'M4C_PREREQUISITE_KNOWN_MODE');
    return array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES,$mode==='verify'?M4C_PREPARE_PREREQUISITES:[]);
}
function m4c_entry_environment(array $binding,array $context,string $proof,string $expectedScope='MS4_CUSTOMER_REAL_ENVIRONMENT'): array
{
    m4c_expect(in_array($expectedScope,['MS4_CUSTOMER_REAL_ENVIRONMENT','MS4_CUSTOMER_H5_MS5_REAL_ENVIRONMENT'],true),'M4C_FIXED_REAL_ENVIRONMENT_SCOPE');
    $base='/run/audit/evidence/';$environment=m4c_entry_read($base.$proof.'-environment.json');
    m4c_entry_keys($environment,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
    m4c_expect($environment['schema']===1&&$environment['scope']===$expectedScope
        &&$environment['run_id']===$binding['run_id']&&$environment['source_sha']===$binding['source_sha']
        &&$environment['container_id']===$binding['container_id']&&$environment['freeze_sha256']===$context['freeze_sha256'],
        'M4C_ACTUAL_ENVIRONMENT_BINDING');
    $values=$environment['environment'];m4c_expect(is_array($values),'M4C_PUBLIC_ENVIRONMENT_OBJECT');
    $native=m4c_entry_read($base.$proof.'-environment-native.json');
    m4c_expect($native['stdout_sha256']===$environment['native_projection_sha256'],'M4C_ACTUAL_ENVIRONMENT_NATIVE_PROJECTION_BINDING');
    return m4c_entry_native_environment($values,$native);
}
/** Shared native six-value proof only; callers own their real receipt/binding/descriptor identities. */
function m4c_entry_native_environment(array $values,array $native): array
{
    m4c_entry_keys($values,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED',
        'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER']);
    m4c_expect($values['APP_ENV']==='testing'&&$values['PHP_MARKETPLACE_ENABLED']==='true'
        &&$values['PHP_MARKETPLACE_TRADE_ENABLED']==='true'&&$values['PHP_MARKETPLACE_PAYMENT_TEST_ENABLED']==='true'
        &&$values['PHP_CACHE_DRIVER']==='redis'&&is_string($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])
        &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])===1
        &&bccomp($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'4294967295',0)<=0,'M4C_REAL_ENABLED_CONFIGURATION_VALUES');
    $lines=[];foreach($values as$key=>$value){m4c_expect(getenv($key)===$value,'M4C_ACTUAL_PROCESS_ENVIRONMENT');
        $lines[]=json_encode($key.'='.$value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    m4c_expect($native['stage']==='customer-public-environment-inspect'&&$native['native_exit']===0
        &&$native['stderr_bytes']===0&&$native['stderr_sha256']===hash('sha256','')&&$native['private_environment_logs_omitted']===true
        &&is_int($native['stdout_bytes'])&&$native['stdout_bytes']>0&&is_string($native['stdout_sha256'])
        &&preg_match('/\A[a-f0-9]{64}\z/D',$native['stdout_sha256'])===1,'M4C_REAL_NATIVE_ENVIRONMENT_PROJECTION');
    $matched=false;$permutations=0;
    $visit=static function(array $remaining,array $ordered)use(&$visit,&$matched,&$permutations,$native):void{
        if($remaining===[]){++$permutations;foreach(["\n","\n\n"]as$ending){$raw=implode("\n",$ordered).$ending;
            if(strlen($raw)===$native['stdout_bytes']&&hash('sha256',$raw)===$native['stdout_sha256'])$matched=true;}return;}
        foreach($remaining as$index=>$line){$next=$remaining;unset($next[$index]);$visit(array_values($next),array_merge($ordered,[$line]));}
    };
    $visit($lines,[]);m4c_expect($permutations===720&&$matched,'M4C_COMPLETE_SIX_LINE_NATIVE_ENV_HASH_ALL_PERMUTATIONS');
    return $values;
}
function m4c_entry_migration_sources(array $rows): void
{
    m4c_expect(array_is_list($rows),'M4C_TEN_MIGRATION_SOURCE_LIST');$paths=[];
    foreach($rows as$row){m4c_expect(is_array($row),'M4C_MIGRATION_SOURCE_OBJECT');m4c_entry_keys($row,['path','bytes','sha256']);
        m4c_expect(is_string($row['path'])&&in_array($row['path'],M4C_MIGRATION_SOURCES,true)&&!isset($paths[$row['path']])
            &&is_int($row['bytes'])&&$row['bytes']>=0&&is_string($row['sha256'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])===1,'M4C_EXACT_MIGRATION_SOURCE_DESCRIPTOR');$paths[$row['path']]=true;
        foreach(['/workspace','/source']as$root){$path=$root.'/'.$row['path'];clearstatcache(true,$path);
            m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path&&filesize($path)===$row['bytes']
                &&m4c_entry_hash($path)===$row['sha256'],'M4C_TEN_MIGRATION_DUAL_RO_SOURCE_BYTES');}}
    $paths=array_keys($paths);sort($paths,SORT_STRING);$expected=M4C_MIGRATION_SOURCES;sort($expected,SORT_STRING);
    m4c_expect($paths===$expected,'M4C_COMPLETE_TEN_MIGRATION_SOURCE_CLOSURE');
}
function m4c_entry_native_child(array $child): void
{
    m4c_expect(is_int($child['pid']??null)&&$child['pid']>0&&($child['native_exit']??null)===0
        &&array_key_exists('capture_error_class',$child)&&$child['capture_error_class']===null
        &&is_int($child['stdout_bytes']??null)&&$child['stdout_bytes']>0&&$child['stdout_bytes']<=1048576
        &&($child['stderr_bytes']??null)===0&&($child['stderr_sha256']??null)===hash('sha256','')
        &&is_string($child['stdout_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$child['stdout_sha256'])===1,
        'M4C_REAL_NORMAL_PREREQUISITE_CHILD');
    $close=$child['proc_close_exit']??null;$terminal=$child['same_handle_terminal']??null;
    m4c_expect(in_array($close,[0,-1],true)&&($close===0||$terminal!==null),'M4C_SAME_HANDLE_NATIVE_CHILD_RETURN');
    if($terminal!==null)m4c_entry_same($terminal,['running'=>false,'signaled'=>false,'stopped'=>false,'exitcode'=>0]);
}
function m4c_entry_predecessor(array $source,string $proof,string $role,array $command,string $createdFile,string $terminalFile): array
{
    $created=m4c_entry_terminal($source,$proof,$role,$command,$createdFile,$terminalFile);
    $terminal=m4c_entry_read('/run/audit/evidence/'.$terminalFile);$network=$source['context']['initial_network'];
    m4c_expect($created['hostname']===substr($created['id'],0,12)&&$created['restart_count']===0&&$created['read_only']===true
        &&$created['privileged']===false&&$created['workdir']==='/workspace'&&$created['cap_drop']===['ALL']
        &&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true)
        &&$created['network_mode']===$network['Name']&&array_keys($created['networks'])===[$network['Name']]
        &&in_array($created['networks'][$network['Name']]['NetworkID'],['',$network['Id']],true)
        &&in_array($created['port_bindings'],[null,[]],true),'M4C_ORIGINAL_PREDECESSOR_ISOLATED_ROLE');
    m4c_expect($created['ports']===null||is_array($created['ports']),'M4C_PREDECESSOR_PORT_MAP');
    foreach($created['ports']??[]as$value)m4c_expect(in_array($value,[null,[]],true),'M4C_PREDECESSOR_NO_PUBLISHED_PORTS');
    $binds=['/workspace'=>[$source['context']['repo_dir'],false],'/source'=>[$source['context']['repo_dir'],false],
        '/run/audit'=>[$source['context']['run_dir'],true]];
    if($proof==='initializer')$binds['/run/ms4-prep']=[$source['context']['prep_dir'],false];
    $volumes=['/run/audit-bootstrap'=>'bootstrap-secrets','/run/audit-app'=>'app-secrets'];$seen=[];
    foreach($created['mounts']as$mount){$destination=$mount['Destination'];
        m4c_expect(!isset($seen[$destination])&&!str_contains((string)($mount['Source']??''),'docker.sock'),'M4C_PREDECESSOR_UNIQUE_MOUNTS');$seen[$destination]=true;
        if(isset($binds[$destination]))m4c_expect($mount['Type']==='bind'&&$mount['RW']===$binds[$destination][1]
            &&audit_canonical_host_bind_source($mount['Source'])===audit_canonical_host_bind_source($binds[$destination][0]),'M4C_PREDECESSOR_ORIGINAL_HOST_BIND');
        elseif(isset($volumes[$destination]))m4c_expect($mount['Type']==='volume'&&$mount['RW']===false
            &&$mount['Name']===$source['context']['project'].'_'.$volumes[$destination],'M4C_PREDECESSOR_PRIVATE_RO_VOLUME');
        else m4c_expect($destination==='/tmp'&&$mount['Type']==='tmpfs'&&$mount['RW']===true,'M4C_PREDECESSOR_ONLY_PRIVATE_TMPFS');}
    foreach(array_merge(array_keys($binds),array_keys($volumes))as$destination)m4c_expect(isset($seen[$destination]),'M4C_PREDECESSOR_ALL_REQUIRED_MOUNTS');
    m4c_expect(array_keys($created['tmpfs'])===['/tmp'],'M4C_PREDECESSOR_ONE_TMPFS');
    foreach(['started_at','finished_at']as$key)m4c_expect(is_string($terminal[$key])&&strtotime($terminal[$key])!==false
        &&strtotime($terminal[$key])>0,'M4C_PREDECESSOR_REAL_START_FINISH');
    m4c_expect(strtotime($terminal['finished_at'])>=strtotime($terminal['started_at']),'M4C_PREDECESSOR_TERMINAL_TIME_ORDER');return $created;
}
/** Fixed historical G8+F4 only; current callers retain their own live-role and endpoint gates. */
function m4c_entry_ms5_history(array $source): array
{
    $base='/run/audit/evidence/';$names=array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES);$hashes=[];
    m4c_expect(is_array($source['prerequisites_sha256']??null),'M4C_HISTORY_PREREQUISITE_HASH_OBJECT');
    foreach($names as$name){$hash=m4c_entry_hash($base.$name);
        m4c_expect(($source['prerequisites_sha256'][$name]??null)===$hash,'M4C_HISTORY_COMPLETE_ORIGINAL_HASH');$hashes[$name]=$hash;}
    $contextPath='/run/audit/actual-run-context.json';$contextHash=m4c_entry_hash($contextPath);$context=m4c_entry_read($contextPath);
    $freezePath='/run/audit/preparation-source-binding.json';
    m4c_expect($context===$source['context']&&$contextHash===m4c_entry_hash($contextPath)
        &&$context['run_id']===$source['run_id']&&$context['source_sha']===$source['source_sha']
        &&$context['project']==='tianli-audit-'.$source['run_id']&&$context['freeze_sha256']===$source['freeze_sha256']
        &&$context['repo_dir']===$source['requested_repo_source']&&$context['run_dir']===$source['requested_run_source']
        &&$context['prep_dir']===$source['requested_prep_source']&&m4c_entry_hash($freezePath)===$source['freeze_sha256']
        &&m4c_entry_read($freezePath)===$source['freeze'],'M4C_HISTORY_ACTUAL_COMPLETE_ORIGINAL_CONTEXT_AND_FREEZE');
    $network=$context['initial_network'];m4c_entry_keys($network,['Id','Name','Internal','Labels']);
    m4c_expect(is_string($context['network_id'])&&preg_match('/\A[a-f0-9]{64}\z/D',$context['network_id'])===1
        &&$network['Id']===$context['network_id']&&$network['Name']===$context['project'].'_isolation'&&$network['Internal']===true
        &&$network['Labels']['tianli.audit']==='1'&&$network['Labels']['tianli.audit.run']===$source['run_id']
        &&$network['Labels']['com.docker.compose.project']===$context['project'],'M4C_HISTORY_ORIGINAL_NATIVE_INTERNAL_NETWORK');
    m4c_entry_keys($context['initial_service_ids'],['audit-mysql','audit-redis']);
    m4c_entry_keys($context['initial_service_created'],['audit-mysql','audit-redis']);
    m4c_expect(count(array_unique(array_values($context['initial_service_ids']),SORT_STRING))===2,'M4C_HISTORY_TWO_ORIGINAL_DB_CIDS');
    $dbImages=['audit-mysql'=>'sha256:c7a313f1402365dc91253bdbdb1e74c6a361b036106d8bd25b8ec8e2dd0069f4',
        'audit-redis'=>'sha256:6c8f6edc1aa871a76a257ef86a2089d108e64b78d16f45b89f09c6a4ab30d07e'];
    foreach($dbImages as$role=>$image){$facts=$context['initial_service_created'][$role];
        m4c_entry_keys($facts,['id','image','hostname','user','stop_signal','labels','mounts','tmpfs','read_only','privileged',
            'network_mode','status','command','entrypoint','workdir','restart_count','pid','exit_code','networks','ports',
            'port_bindings','cap_drop','security_options','started_at','finished_at','created_at']);
        m4c_expect(is_string($facts['id'])&&preg_match('/\A[a-f0-9]{64}\z/D',$facts['id'])===1
            &&$facts['id']===$context['initial_service_ids'][$role]&&$facts['image']===$image
            &&$facts['hostname']===substr($facts['id'],0,12)&&$facts['status']==='created'&&$facts['pid']===0
            &&$facts['restart_count']===0&&$facts['labels']['tianli.audit']==='1'&&$facts['labels']['tianli.audit.run']===$source['run_id']
            &&$facts['labels']['com.docker.compose.project']===$context['project']&&$facts['labels']['com.docker.compose.service']===$role
            &&$facts['network_mode']===$network['Name']&&array_keys($facts['networks'])===[$network['Name']]
            &&in_array($facts['networks'][$network['Name']]['NetworkID'],['',$network['Id']],true)
            &&is_string($facts['networks'][$network['Name']]['IPAddress'])
            &&($facts['networks'][$network['Name']]['IPAddress']===''
                ||filter_var($facts['networks'][$network['Name']]['IPAddress'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false)
            &&in_array($facts['port_bindings'],[null,[]],true),'M4C_HISTORY_COMPLETE_ORIGINAL_DB_CREATED_IDENTITY');
        m4c_expect($facts['ports']===null||is_array($facts['ports']),'M4C_HISTORY_ORIGINAL_DB_NATIVE_PORT_MAP');
        foreach($facts['ports']??[]as$value)m4c_expect(in_array($value,[null,[]],true),'M4C_HISTORY_ORIGINAL_DB_NO_PUBLISHED_PORTS');
        $mounts=$role==='audit-mysql'?['/var/lib/mysql'=>['mysql-data',true],'/run/audit-bootstrap'=>['bootstrap-secrets',false]]
            :['/run/audit-redis'=>['redis-secrets',false],'/data'=>['redis-data',true]];
        m4c_expect(is_array($facts['mounts'])&&array_is_list($facts['mounts'])&&count($facts['mounts'])===count($mounts),
            'M4C_HISTORY_COMPLETE_ORIGINAL_DB_MOUNT_SET');$seen=[];
        foreach($facts['mounts']as$mount){$destination=$mount['Destination'];
            m4c_expect(isset($mounts[$destination])&&!isset($seen[$destination])&&$mount['Type']==='volume'
                &&$mount['Name']===$context['project'].'_'.$mounts[$destination][0]&&$mount['RW']===$mounts[$destination][1],
                'M4C_HISTORY_ORIGINAL_DB_NATIVE_VOLUME_MOUNT');$seen[$destination]=true;}}
    $volumeNames=[];$volumeIdentities=[];
    m4c_expect(is_array($context['initial_volumes'])&&array_is_list($context['initial_volumes'])&&count($context['initial_volumes'])===7,
        'M4C_HISTORY_ORIGINAL_SEVEN_NATIVE_VOLUME_ROWS');
    foreach($context['initial_volumes']as$row){m4c_entry_keys($row,['name','created_at','labels']);
        m4c_expect(is_string($row['name'])&&!isset($volumeIdentities[$row['name']])&&is_string($row['created_at'])&&$row['created_at']!==''
            &&$row['labels']['tianli.audit']==='1'&&$row['labels']['tianli.audit.run']===$source['run_id']
            &&$row['labels']['com.docker.compose.project']===$context['project'],'M4C_HISTORY_COMPLETE_ORIGINAL_VOLUME_IDENTITY');
        $volumeNames[]=$row['name'];$volumeIdentities[$row['name']]=$row['created_at'];}
    $wantedVolumes=[];foreach(['app-secrets','bootstrap-secrets','fixture-secrets','mysql-data','redis-data','redis-secrets','aftersale-evidence']as$name)
        $wantedVolumes[]=$context['project'].'_'.$name;
    $sortedVolumes=$volumeNames;sort($sortedVolumes,SORT_STRING);sort($wantedVolumes,SORT_STRING);
    m4c_expect($sortedVolumes===$wantedVolumes,'M4C_HISTORY_EXACT_ORIGINAL_SEVEN_VOLUME_NAMES');
    $created=m4c_entry_predecessor($source,'initializer','audit-ms4-initialize',['/run/ms4-prep/ms4-initialize.php'],
        'initializer-created.json','initializer-completed-terminal.json');$init=m4c_entry_read($base.'initializer-result.json');
    m4c_expect(($init['schema']??null)===1&&($init['scope']??null)==='MS5_PAYMENT_PREREQUISITES_ONLY'
        &&($init['status']??null)==='VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY'&&($init['native_exit']??null)===0
        &&($init['run_id']??null)===$source['run_id']&&($init['source_sha']??null)===$source['source_sha']
        &&($init['private_cleanup_verified']??null)===true&&($init['binding']['container_id']??null)===$created['id']
        &&($init['binding']['source_sha']??null)===$source['source_sha']&&($init['binding']['created_sha256']??null)===$hashes['initializer-created.json']
        &&($init['binding']['freeze_sha256']??null)===$source['freeze_sha256'],'M4C_ACTUAL_NINE_INITIALIZER_BINDING');
    $sequence=[['marketplace-ms1.php','check'],['marketplace-ms1.php','apply'],['marketplace-ms1.php','check'],
        ['marketplace-ms2.php','check'],['marketplace-ms2.php','apply-structure'],['marketplace-ms2.php','check'],
        ['marketplace-ms4.php','check'],['marketplace-ms4.php','apply-structure'],['marketplace-ms4.php','check']];
    m4c_expect(is_array($init['children']??null)&&array_is_list($init['children'])&&count($init['children'])===9,'M4C_NINE_REAL_INITIALIZER_CHILDREN');$pids=[];
    foreach($sequence as$index=>[$script,$mode]){$child=$init['children'][$index];m4c_entry_native_child($child);$result=$child['result'];
        m4c_expect(($child['script']??null)===$script&&($child['mode']??null)===$mode&&!isset($pids[$child['pid']])
            &&($child['script_sha256']??null)===m4c_entry_hash('/workspace/help/migrations/'.$script)&&is_array($result)
            &&($result['mode']??null)===$mode&&($result['issues']??null)===[]
            &&($result['state']??null)===($index%3===0?'applicable':'completed')&&($result['changed']??null)===($index%3===1),
            'M4C_EXACT_NINE_SOURCE_BOUND_NORMAL_CHILDREN');$pids[$child['pid']]=true;
        if($index>=6)m4c_expect(($result['native_exit']??null)===0&&($result['source_sha256']??null)===$child['script_sha256']
            &&($result['marker']??null)==='marketplace-ms4-structure-v1'&&($result['pending_steps_known']??null)===true
            &&array_key_exists('failure_phase',$result)&&$result['failure_phase']===null&&($result['trade_enabled']??null)===false
            &&($result['acceptance']??null)==='NOT RUN','M4C_ACTUAL_MS4_STRUCTURE_PREDECESSOR');}
    $self=$init['actual_self_mer_id']??null;
    m4c_expect(is_int($self)&&$self>0&&$self<=4294967295&&$self===$source['self_operated_mer_id']
        &&($init['children'][4]['result']['self_mer_id']??null)===$self&&($init['children'][5]['result']['self_mer_id']??null)===$self
        &&($init['children'][5]['result']['ownership_phase']??null)==='structure'&&($init['children'][5]['result']['enabled']??null)===false,
        'M4C_REAL_NATIVE_MS2_SELF_PRIMARY_KEY');
    $structure=$init['prerequisite_structure']??[];
    m4c_expect(($structure['pre_ms4_count']??null)===164&&($structure['post_ms4_count']??null)===166
        &&($structure['added_tables']??null)===['eb_marketplace_order_idempotency','eb_marketplace_stock_movement']
        &&($structure['ms4_completed']??null)===true&&($structure['ms5_tables_absent']??null)===true
        &&($structure['ms4_source_sha256']??null)===m4c_entry_hash('/workspace/help/migrations/marketplace-ms4.php'),
        'M4C_ACTUAL_MS4_STRUCTURE_BEFORE_MS5');
    $migrationCreated=m4c_entry_predecessor($source,'driver','audit-ms5-payment-migration',
        ['help/audit-regressions/isolated/ms5-payment-migration.php'],'ms5-payment-migration-container-created.json','driver-terminal.json');
    m4c_expect($created['id']!==$migrationCreated['id'],'M4C_HISTORY_DISTINCT_INITIALIZER_AND_MIGRATION_CIDS');
    $formal=m4c_entry_read($base.'ms5-payment-migration-formal-manifest.json');
    m4c_expect($formal['run_id']===$source['run_id']&&$formal['commit_sha']===$source['source_sha']
        &&$formal['project']===$context['project']&&$formal['guard_mode']==='read-only'&&$formal['isolation']==='ISO-FULL'
        &&$formal['network_internal']===true,'M4C_HISTORY_ORIGINAL_FORMAL_ISOLATION');
    m4c_entry_same($formal['network'],['id'=>$network['Id'],'name'=>$network['Name']]);
    $formalVolumes=$formal['volumes'];sort($formalVolumes,SORT_STRING);
    m4c_expect($formalVolumes===$sortedVolumes,'M4C_HISTORY_FORMAL_EXACT_ORIGINAL_SEVEN_VOLUMES');
    m4c_entry_same($formal['volume_identities'],$volumeIdentities);
    m4c_entry_keys($formal['services'],['audit-ms5-payment-migration','audit-mysql','audit-redis']);
    m4c_expect($formal['services']['audit-ms5-payment-migration']['id']===$migrationCreated['id']
        &&$formal['services']['audit-ms5-payment-migration']['image_id']===M4C_IMAGE,'M4C_ORIGINAL_MIGRATION_FORMAL_ROLE');
    foreach($dbImages as$role=>$image){m4c_entry_keys($formal['services'][$role],['id','address','image_id']);
        m4c_expect($formal['services'][$role]['id']===$context['initial_service_ids'][$role]
            &&$formal['services'][$role]['image_id']===$image&&is_string($formal['services'][$role]['address'])
            &&filter_var($formal['services'][$role]['address'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false,
            'M4C_HISTORY_ORIGINAL_FORMAL_DB_IDENTITY_AND_IPV4');}
    $binding=m4c_entry_read($base.'ms5-payment-migration-source-binding.json');
    m4c_entry_keys($binding,['schema','run_id','source_sha','requested_repo_source','requested_run_source','entry_sha256','cli_sha256','source_files']);
    m4c_expect($binding['schema']===1&&$binding['run_id']===$source['run_id']&&$binding['source_sha']===$source['source_sha']
        &&$binding['requested_repo_source']===$source['requested_repo_source']&&$binding['requested_run_source']===$source['requested_run_source']
        &&$binding['entry_sha256']===m4c_entry_hash('/workspace/help/audit-regressions/isolated/ms5-payment-migration.php')
        &&$binding['cli_sha256']===m4c_entry_hash('/workspace/help/migrations/marketplace-ms5-payment.php'),'M4C_ORIGINAL_MS5_EIGHT_KEY_BINDING');
    m4c_entry_migration_sources($binding['source_files']);m4c_entry_same($source['freeze']['driver_source_files'],$binding['source_files']);
    $migration=m4c_entry_read($base.'ms5-payment-migration-normal.json');
    m4c_expect(($migration['schema']??null)===1&&($migration['scope']??null)==='MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'
        &&($migration['status']??null)==='VERIFIED_MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'
        &&array_key_exists('native_exit',$migration)&&$migration['native_exit']===null
        &&($migration['native_exit_observation']??null)==='HOST_NATIVE_REQUIRED'&&($migration['entry_return_code']??null)===0
        &&($migration['termination']??null)==='NORMAL_RETURN'&&($migration['private_cleanup_verified']??null)===true
        &&($migration['private_cleanup_state']??null)==='OWN_FILE_REMOVED'
        &&($migration['binding']['container_id']??null)===$migrationCreated['id']&&($migration['binding']['run_id']??null)===$source['run_id']
        &&($migration['binding']['source_sha']??null)===$source['source_sha']
        &&($migration['binding']['native_created_sha256']??null)===$hashes['ms5-payment-migration-container-created.json']
        &&($migration['binding']['binding_sha256']??null)===$hashes['ms5-payment-migration-source-binding.json']
        &&($migration['binding']['entry_sha256']??null)===$binding['entry_sha256']&&($migration['binding']['cli_sha256']??null)===$binding['cli_sha256']
        &&($migration['binding']['source_files']??null)===10&&($migration['actual_command_counts']??null)===['check'=>2,'apply-structure'=>2]
        &&is_array($migration['children']??null)&&array_is_list($migration['children'])&&count($migration['children'])===4,
        'M4C_REAL_MS5_RETURN_AND_SEPARATE_HOST_NATIVE_ZERO');
    $pids=[];$steps=['marketplace_payment_registry','marketplace_payment_attempt'];
    foreach(['check','apply-structure','check','apply-structure']as$index=>$mode){$child=$migration['children'][$index];m4c_entry_native_child($child);
        m4c_expect(($child['mode']??null)===$mode&&!isset($pids[$child['pid']]),'M4C_FOUR_DISTINCT_NATIVE_MS5_CHILDREN');$pids[$child['pid']]=true;
        $result=$child['result'];m4c_expect(is_array($result),'M4C_MS5_CHILD_RESULT_OBJECT');
        m4c_entry_keys($result,['mode','state','native_exit','changed','applied_steps','pending_steps','pending_steps_known',
            'issues','failure_phase','marker','source_sha256','history_mapping','payment_producer','acceptance']);
        m4c_expect($result['mode']===$mode&&$result['native_exit']===0&&$result['state']===($index===0?'applicable':'completed')
            &&$result['changed']===($index===1)&&$result['pending_steps_known']===true&&$result['issues']===[]&&$result['failure_phase']===null
            &&$result['marker']==='marketplace-ms5-payment-structure-v1'&&$result['source_sha256']===$binding['cli_sha256']
            &&$result['history_mapping']==='NOT RUN'&&$result['payment_producer']==='NOT IMPLEMENTED'&&$result['acceptance']==='NOT RUN'
            &&$result['pending_steps']===($index===0?$steps:[])&&$result['applied_steps']===($index===1?$steps:[]),
            'M4C_EXACT_FOUR_REAL_MS5_CLI_RESULTS');}
    $bootstrap=m4c_entry_terminal($source,'files-bootstrap','audit-bootstrap',['/run/ms4-prep/ms4-initialize.php','files-bootstrap']);
    m4c_expect(!in_array($bootstrap['id'],[$created['id'],$migrationCreated['id']],true),'M4C_DISTINCT_REAL_FILES_BOOTSTRAP_CID');
    $installed=m4c_entry_read($base.'files-bootstrap-result.json');$installation=$installed['installation'];$shutdown=m4c_entry_read($base.'files-bootstrap-shutdown.json');
    m4c_expect($installed['schema']===1&&$installed['scope']==='MS5_PAYMENT_PREREQUISITES_ONLY'
        &&$installed['status']==='VERIFIED_CUSTOMER_FILES_BOOTSTRAP_ONLY'&&$installed['native_exit']===0
        &&$installed['run_id']===$source['run_id']&&$installed['source_sha']===$source['source_sha']
        &&$installed['binding']['source_sha']===$source['source_sha']&&$installed['binding']['container_id']===$bootstrap['id']
        &&$installed['binding']['freeze_sha256']===$source['freeze_sha256']&&$installed['binding']['created_sha256']===$hashes['files-bootstrap-created.json']
        &&$installed['actual_self_mer_id']===$self&&$installed['initializer_result_sha256']===$hashes['initializer-result.json']
        &&$installed['migration_result_sha256']===$hashes['ms5-payment-migration-normal.json']
        &&$installation['account']==='audit_migrator'&&is_int($installation['checksum_rows'])&&$installation['checksum_rows']>0
        &&$installation['source_matches']===true&&$installation['version_marker_matches']===true
        &&$installation['application_account']==='audit_app'&&$installation['application_dml_only']===true
        &&$shutdown['phase']==='files-bootstrap'&&$shutdown['fatal']===false&&$shutdown['primary_failure']===null&&$shutdown['cleanup_failure']===null,
        'M4C_REAL_ONCE_ONLY_MIGRATOR_FILES_BOOTSTRAP');
    foreach($names as$name)m4c_expect($hashes[$name]===m4c_entry_hash($base.$name),'M4C_HISTORY_ORIGINAL_BYTES_UNCHANGED');
    m4c_expect(m4c_entry_hash($contextPath)===$contextHash&&m4c_entry_read($contextPath)===$context
        &&$context===$source['context']&&m4c_entry_hash($freezePath)===$source['freeze_sha256']
        &&m4c_entry_read($freezePath)===$source['freeze'],'M4C_HISTORY_ORIGINAL_CONTEXT_AND_FREEZE_BYTES_UNCHANGED');
    return ['self_operated_mer_id'=>$self,'initializer_id'=>$created['id'],'migration_id'=>$migrationCreated['id'],
        'files_id'=>$bootstrap['id'],'formal'=>$formal,'context_sha256'=>$contextHash,'history_sha256'=>$hashes];
}
function m4c_entry_ms5_prerequisites(array $source): void
{
    $base='/run/audit/evidence/';$hashes=$source['prerequisites_sha256'];m4c_expect(is_array($hashes),'M4C_PREREQUISITE_HASH_OBJECT');
    m4c_entry_keys($hashes,m4c_entry_prerequisite_names($source['mode']));
    foreach(m4c_entry_prerequisite_names($source['mode'])as$name)m4c_expect($hashes[$name]===m4c_entry_hash($base.$name),'M4C_ORIGINAL_COMPLETE_PREREQUISITE_HASH');
    $history=m4c_entry_ms5_history($source);
    m4c_entry_same($history['history_sha256'],array_intersect_key($hashes,array_flip(array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES))));
    m4c_expect(count(array_unique([$history['initializer_id'],$history['migration_id'],$source['container_id']]))===3,'M4C_DISTINCT_REAL_PREDECESSOR_AND_CUSTOMER_CIDS');
    $formal=$history['formal'];$manifest=audit_manifest();
    foreach(['run_id','commit_sha','project','guard_mode','isolation','network_internal','network','volumes','volume_identities']as$key)
        m4c_entry_same($formal[$key],$manifest[$key]);
    m4c_entry_keys($formal['services'],['audit-ms5-payment-migration','audit-mysql','audit-redis']);
    m4c_expect($formal['services']['audit-ms5-payment-migration']['id']===$history['migration_id']
        &&$formal['services']['audit-ms5-payment-migration']['image_id']===M4C_IMAGE,'M4C_ORIGINAL_MIGRATION_FORMAL_ROLE');
    foreach(['audit-mysql','audit-redis']as$role)foreach(['id','image_id','address']as$key)
        m4c_expect($formal['services'][$role][$key]===$manifest['services'][$role][$key],'M4C_ORIGINAL_SHARED_NATIVE_ENDPOINTS');
    m4c_expect(!in_array($history['files_id'],[$history['initializer_id'],$history['migration_id'],$source['container_id']],true),'M4C_DISTINCT_REAL_FILES_BOOTSTRAP_CID');
    if($source['mode']==='verify'){
        $prepare=m4c_entry_terminal($source,'customer-prepare',M4C_MODE['prepare']['role'],[M4C_ENTRY,'prepare']);
        m4c_expect(!in_array($prepare['id'],[$history['initializer_id'],$history['migration_id'],$history['files_id'],$source['container_id']],true),
            'M4C_DISTINCT_REAL_PREPARE_AND_VERIFY_CIDS');
        $prepareBinding=m4c_entry_read($base.M4C_MODE['prepare']['binding']);$wanted=$source;
        $wanted=array_intersect_key($wanted,array_flip(['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
            'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']));
        $wanted['scope']=M4C_MODE['prepare']['binding_scope'];$wanted['container_id']=$prepare['id'];
        $wanted['native_created_sha256']=$hashes['customer-prepare-created.json'];$wanted['prerequisites_sha256']=array_intersect_key($hashes,
            array_flip(m4c_entry_prerequisite_names('prepare')));m4c_entry_same($prepareBinding,$wanted);
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
            'M4C_REAL_SOURCE_BOUND_PREPARE_RESULT_BEFORE_HTTP');
    }
}
/** Recover the five original native snapshots from their bounded public encoding. */
function m4c_entry_early_snapshots(array $result): void
{
    $stages=['before','fulfillment','aftersale','aftersale_menus','review_menus'];$snapshots=$result['snapshots'];
    m4c_expect(array_keys($snapshots)===$stages,'M4C_EARLY_FIVE_ACTUAL_SNAPSHOT_STAGES');
    foreach($snapshots as$stage=>$snapshot){
        m4c_entry_keys($snapshot,['names','tables','redis','modes','metadata_observer_modes','native_snapshot_sha256']);
        $names=$snapshot['names'];m4c_expect(is_array($names)&&array_is_list($names)&&$names!==[],'M4C_EARLY_COMPLETE_NATIVE_TABLE_NAME_LIST');
        foreach($names as$name)m4c_expect(is_string($name)&&preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$name)===1,'M4C_EARLY_NATIVE_TABLE_NAME');
        $sorted=$names;sort($sorted,SORT_STRING);m4c_expect($names===$sorted&&count($names)===count(array_unique($names,SORT_STRING))
            &&is_array($snapshot['tables'])&&array_keys($snapshot['tables'])===$names,'M4C_EARLY_SORTED_CLOSED_NATIVE_TABLE_SET');$nativeTables=[];
        foreach($snapshot['tables']as$name=>$table){
            m4c_entry_keys($table,['ddl_sha256','projected_columns','projection','metadata_sha256','metadata_serialized_base64']);
            foreach(['ddl_sha256','metadata_sha256']as$key)m4c_expect(is_string($table[$key])&&preg_match('/\A[a-f0-9]{64}\z/D',$table[$key])===1,'M4C_EARLY_ACTUAL_TABLE_DIGEST');
            m4c_expect(is_string($table['metadata_serialized_base64'])&&strlen($table['metadata_serialized_base64'])<=16777216,'M4C_EARLY_BOUND_BINARY_SAFE_METADATA');
            $bytes=base64_decode($table['metadata_serialized_base64'],true);
            m4c_expect(is_string($bytes)&&base64_encode($bytes)===$table['metadata_serialized_base64']&&hash('sha256',$bytes)===$table['metadata_sha256'],
                'M4C_EARLY_CANONICAL_BASE64_COMPLETE_METADATA_HASH');
            $metadata=unserialize($bytes,['allowed_classes'=>false,'max_depth'=>64]);
            m4c_expect(is_array($metadata)&&serialize($metadata)===$bytes,'M4C_EARLY_NATIVE_METADATA_STRICT_ROUNDTRIP');
            m4c_entry_keys($metadata,['table','columns','indexes','constraints','checks','foreign','triggers']);
            array_walk_recursive($metadata,static function($value):void{m4c_expect($value===null||is_string($value)||is_int($value),'M4C_EARLY_METADATA_ONLY_NATIVE_SCALAR_LEAVES');});
            foreach($metadata as$section)m4c_expect(is_array($section),'M4C_EARLY_COMPLETE_NATIVE_METADATA_SECTIONS');
            m4c_expect($metadata['table']['TABLE_TYPE']==='BASE TABLE'&&is_array($table['projected_columns'])&&array_is_list($table['projected_columns'])
                &&$table['projected_columns']!==[]&&$table['projected_columns']===array_keys($metadata['columns']),'M4C_EARLY_ALL_ACTUAL_NATIVE_COLUMNS');
            m4c_entry_keys($table['projection'],['encoding','rows','sha256']);$projection=$table['projection'];
            m4c_expect($projection['encoding']==='php-serialize-row-sha256-sorted-multiset-v1'&&is_int($projection['rows'])&&$projection['rows']>=0
                &&is_string($projection['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$projection['sha256'])===1,'M4C_EARLY_NATIVE_ALL_ROW_MULTISET');
            $nativeTables[$name]=['metadata'=>$metadata,'ddl_sha256'=>$table['ddl_sha256'],'projected_columns'=>$table['projected_columns'],'projection'=>$projection];
        }
        $modes=$snapshot['modes'];$observer=$snapshot['metadata_observer_modes'];
        m4c_expect(is_array($modes)&&array_is_list($modes)&&count($modes)===1&&is_array($observer)&&array_is_list($observer)&&count($observer)===1,
            'M4C_EARLY_COMPLETE_NATIVE_SQL_MODE_ROWS');
        $modeKeys=['session_mode','global_mode','session_innodb','global_innodb'];m4c_entry_keys($modes[0],$modeKeys);
        m4c_entry_keys($observer[0],[...$modeKeys,'session_stats_expiry','global_stats_expiry']);
        m4c_expect(is_string($modes[0]['session_mode'])&&is_string($modes[0]['global_mode'])
            &&in_array($modes[0]['session_innodb'],[0,1],true)&&in_array($modes[0]['global_innodb'],[0,1],true)
            &&array_intersect_key($observer[0],array_flip($modeKeys))===$modes[0]&&$observer[0]['session_stats_expiry']===0
            &&is_int($observer[0]['global_stats_expiry'])&&$observer[0]['global_stats_expiry']>=0&&$observer[0]['global_stats_expiry']<=4294967295,
            'M4C_EARLY_SIX_ACTUAL_NATIVE_OBSERVER_MODES');
        m4c_expect(is_array($snapshot['redis']),'M4C_EARLY_COMPLETE_HISTORICAL_REDIS');$redis=$snapshot['redis'];$sortedRedis=$redis;ksort($sortedRedis,SORT_STRING);
        m4c_expect($redis===$sortedRedis,'M4C_EARLY_SORTED_ABSOLUTE_REDIS');
        foreach($redis as$key=>$entry){m4c_entry_keys($entry,['sha256','expires']);m4c_expect(is_string($key)&&preg_match('/\A[a-f0-9]{64}\z/D',$key)===1
            &&is_string($entry['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$entry['sha256'])===1&&is_int($entry['expires'])&&($entry['expires']===-1||$entry['expires']>0),
            'M4C_EARLY_ACTUAL_HISTORICAL_REDIS_VALUE_AND_ABSOLUTE_EXPIRY');}
        $native=['names'=>$names,'tables'=>$nativeTables,'redis'=>$redis,'modes'=>$modes,'metadata_observer_modes'=>$observer];
        m4c_expect(is_string($snapshot['native_snapshot_sha256'])&&hash('sha256',serialize($native))===$snapshot['native_snapshot_sha256'],
            'M4C_EARLY_REBUILT_COMPLETE_FIVE_KEY_NATIVE_SNAPSHOT_SHA');
        $baseline=$snapshots['before'];m4c_expect($modes===$baseline['modes']&&$observer===$baseline['metadata_observer_modes']&&$redis===$baseline['redis'],
            'M4C_EARLY_ALL_FIVE_STAGES_SIX_MODES_AND_REDIS_UNCHANGED');
    }
    $before=$snapshots['before'];$fulfillment=$snapshots['fulfillment'];$aftersale=$snapshots['aftersale'];
    m4c_expect(is_int($result['original_table_count'])&&$result['original_table_count']===count($before['names'])
        &&is_array($result['original_rows'])&&array_keys($result['original_rows'])===$before['names'],'M4C_EARLY_COMPLETE_ORIGINAL_TABLE_COUNTS');
    foreach($before['tables']as$name=>$table)m4c_expect($result['original_rows'][$name]===$table['projection']['rows']&&$fulfillment['tables'][$name]===$table,
        'M4C_EARLY_ORIGINAL_TABLES_NATIVE_SCHEMA_ROWS_AND_COUNTS_RETAINED');
    $added=['eb_marketplace_fulfillment_operation','eb_marketplace_fulfillment_range'];$names=[...$before['names'],...$added];sort($names,SORT_STRING);
    m4c_expect(array_intersect($before['names'],$added)===[]&&$fulfillment['names']===$names,'M4C_EARLY_ONLY_TWO_FULFILLMENT_TABLES');
    $added=['eb_marketplace_aftersale_action','eb_marketplace_aftersale_file'];$names=[...$fulfillment['names'],...$added];sort($names,SORT_STRING);
    m4c_expect(array_intersect($fulfillment['names'],$added)===[]&&$aftersale['names']===$names,'M4C_EARLY_ONLY_TWO_AFTERSALE_TABLES');
    foreach($fulfillment['tables']as$name=>$table)if(!in_array($name,['eb_store_order_refund','eb_marketplace_fulfillment_operation'],true))
        m4c_expect($aftersale['tables'][$name]===$table,'M4C_EARLY_ALL_NON_TARGET_NATIVE_TABLES_RETAINED');
    foreach([['aftersale','aftersale_menus',7],['aftersale_menus','review_menus',3]]as[$oldStage,$newStage,$added]){
        $old=$snapshots[$oldStage];$next=$snapshots[$newStage];m4c_expect($next['names']===$old['names'],'M4C_EARLY_MENU_STAGES_SAME_COMPLETE_TABLE_SET');
        foreach($old['tables']as$name=>$table)if($name!=='eb_system_menus')m4c_expect($next['tables'][$name]===$table,'M4C_EARLY_MENU_STAGES_ONLY_MENU_TABLE_CHANGED');
        m4c_expect($next['tables']['eb_system_menus']['projected_columns']===$old['tables']['eb_system_menus']['projected_columns']
            &&count($next['tables']['eb_system_menus']['projected_columns'])===22
            &&$next['tables']['eb_system_menus']['projection']['rows']===$old['tables']['eb_system_menus']['projection']['rows']+$added,
            'M4C_EARLY_ACTUAL_SEVEN_THEN_THREE_NATIVE_MENU_ROWS');
    }
    $pairs=[['check_first','before'],['check_repeat','fulfillment'],['apply-structure_repeat','fulfillment'],
        ['aftersale_check_first','fulfillment'],['aftersale_check_repeat','aftersale'],['aftersale_apply-structure_repeat','aftersale'],
        ['aftersale_menus_check_first','aftersale'],['aftersale_menus_check_repeat','aftersale_menus'],['aftersale_menus_apply_repeat','aftersale_menus'],
        ['review_menus_check_first','aftersale_menus'],['review_menus_check_repeat','review_menus'],['review_menus_apply_repeat','review_menus']];
    m4c_expect(is_array($result['comparisons'])&&array_is_list($result['comparisons'])&&count($result['comparisons'])===12,'M4C_EARLY_TWELVE_ACTUAL_COMPARISON_RECEIPTS');
    foreach($pairs as$index=>[$phase,$stage])m4c_entry_same($result['comparisons'][$index],
        ['phase'=>$phase,'equals'=>$stage,'snapshot_sha256'=>$snapshots[$stage]['native_snapshot_sha256']]);
}

/** Read only the four actual early public receipts; no migration/App entry is loaded. */
function m4c_entry_early_history(array $source): array
{
    $base='/run/audit/evidence/';$names=['ms4-fulfillment-migration-container-created.json','ms4-fulfillment-migration-source-binding.json',
        'ms4-aftersale-early-migration-normal.json','ms4-fulfillment-migration-terminal.json'];$hashes=[];
    foreach($names as$name)$hashes[$name]=m4c_entry_hash($base.$name);
    $context=$source['context'];$freeze=$source['freeze'];
    m4c_expect(m4c_entry_read('/run/audit/actual-run-context.json')===$context
        &&m4c_entry_read('/run/audit/preparation-source-binding.json')===$freeze
        &&m4c_entry_hash('/run/audit/preparation-source-binding.json')===$source['freeze_sha256']
        &&$context['run_id']===$source['run_id']&&$context['source_sha']===$source['source_sha']
        &&$context['freeze_sha256']===$source['freeze_sha256']&&$context['repo_dir']===$source['requested_repo_source']
        &&$context['run_dir']===$source['requested_run_source'],'M4C_EARLY_ORIGINAL_CONTEXT_FREEZE');
    $created=m4c_entry_predecessor($source,'fulfillment-migration','audit-ms4-fulfillment-migration',
        ['help/audit-regressions/isolated/ms4-fulfillment-migration.php'],$names[0],$names[3]);
    $terminal=m4c_entry_read($base.$names[3]);$binding=m4c_entry_read($base.$names[1]);
    m4c_entry_keys($binding,['schema','run_id','source_sha','requested_repo_source','requested_run_source','entry_sha256','cli_sha256',
        'source_files','initialization_predecessor_sha256','successor_cli_sha256']);
    m4c_expect($binding['schema']===1&&$binding['run_id']===$source['run_id']&&$binding['source_sha']===$source['source_sha']
        &&$binding['requested_repo_source']===$source['requested_repo_source']&&$binding['requested_run_source']===$source['requested_run_source']
        &&$binding['entry_sha256']===m4c_entry_hash('/workspace/help/audit-regressions/isolated/ms4-fulfillment-migration.php')
        &&$binding['cli_sha256']===m4c_entry_hash('/workspace/help/migrations/marketplace-ms4-fulfillment.php'),'M4C_EARLY_REAL_TEN_KEY_SOURCE_BINDING');
    $paths=['crmeb/public/install/crmeb.sql','help/audit-regressions/isolated/lib/guard.php','help/audit-regressions/isolated/lib/harness.php',
        'help/audit-regressions/isolated/ms4-fulfillment-migration.php','help/audit-regressions/isolated/ms5-payment-migration.php',
        'help/migrations/marketplace-ms1.php','help/migrations/marketplace-ms2.php','help/migrations/marketplace-ms4.php',
        'help/migrations/marketplace-ms4.md','help/migrations/marketplace-ms5-payment.php','help/migrations/marketplace-ms5-payment.md',
        'help/migrations/marketplace-ms4-fulfillment.php','help/migrations/marketplace-ms4-aftersale.php',
        'help/migrations/marketplace-ms4-aftersale-menus.php','help/migrations/marketplace-ms4-review-menus.php'];sort($paths,SORT_STRING);
    m4c_expect(is_array($binding['source_files'])&&array_is_list($binding['source_files'])&&count($binding['source_files'])===15,'M4C_EARLY_FIFTEEN_ACTUAL_SOURCES');$sourceHashes=[];
    foreach($binding['source_files']as$row){m4c_entry_keys($row,['path','bytes','sha256']);
        m4c_expect(is_string($row['path'])&&in_array($row['path'],$paths,true)&&!isset($sourceHashes[$row['path']])
            &&is_int($row['bytes'])&&$row['bytes']>=0&&is_string($row['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])===1,
            'M4C_EARLY_EXACT_SOURCE_DESCRIPTOR');
        foreach(['/workspace','/source']as$root){$path=$root.'/'.$row['path'];m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
            &&filesize($path)===$row['bytes']&&m4c_entry_hash($path)===$row['sha256'],'M4C_EARLY_DUAL_RO_SOURCE_BYTES');}
        $sourceHashes[$row['path']]=$row['sha256'];}
    m4c_expect(array_keys($sourceHashes)===$paths,'M4C_EARLY_SORTED_COMPLETE_SOURCE_SET');
    m4c_entry_keys($binding['initialization_predecessor_sha256'],M4C_COMMON_PREREQUISITES);
    foreach(M4C_COMMON_PREREQUISITES as$name)m4c_expect($binding['initialization_predecessor_sha256'][$name]===m4c_entry_hash($base.$name),'M4C_EARLY_ORIGINAL_EIGHT_PREDECESSOR_HASHES');
    $scripts=['aftersale'=>'help/migrations/marketplace-ms4-aftersale.php','aftersale_menus'=>'help/migrations/marketplace-ms4-aftersale-menus.php',
        'review_menus'=>'help/migrations/marketplace-ms4-review-menus.php'];
    m4c_entry_keys($binding['successor_cli_sha256'],array_values($scripts));
    foreach($scripts as$script)m4c_expect($binding['successor_cli_sha256'][$script]===$sourceHashes[$script],'M4C_EARLY_THREE_REAL_SUCCESSOR_SOURCES');
    $result=m4c_entry_read($base.$names[2],67108864);
    m4c_entry_keys($result,['schema','scope','status','binding','children','successor_children','snapshots','comparisons','phase','termination',
        'entry_return_code','native_exit','native_exit_observation','private_cleanup_verified','private_cleanup_state','actual_command_counts',
        'original_table_count','original_rows','independent_new_metadata_verified','menu_leaves','full_source_unchanged','prerequisites_verified',
        'stopped_cases','business_acceptance','diagnostic','cleanup_failure','publication_failure']);
    m4c_expect($result['schema']===1&&$result['scope']==='MS4_EARLY_AFTERSALE_STRUCTURE_AND_MENUS_ONLY'
        &&$result['status']==='VERIFIED_MS4_EARLY_AFTERSALE_STRUCTURE_AND_MENUS_ONLY'&&$result['phase']==='final_binding'
        &&$result['termination']==='NORMAL_RETURN'&&$result['entry_return_code']===0&&$result['native_exit']===null
        &&$result['native_exit_observation']==='HOST_NATIVE_REQUIRED'&&$result['private_cleanup_verified']===true
        &&$result['private_cleanup_state']==='OWN_FILE_REMOVED'&&$result['independent_new_metadata_verified']===true
        &&$result['full_source_unchanged']===true&&$result['prerequisites_verified']===true&&$result['business_acceptance']==='NOT RUN'
        &&$result['diagnostic']===null&&$result['cleanup_failure']===null&&$result['publication_failure']===null
        &&$result['stopped_cases']===['denial_conflict_concurrency_fault_restore'=>'NOT RUN'],'M4C_EARLY_REAL_SOURCE_BOUND_NATIVE_SUCCESS');
    $actual=$result['binding'];m4c_entry_keys($actual,['run_id','container_id','entry_namespace_pid','source_sha','entry_sha256','cli_sha256','source_files',
        'binding_sha256','native_created_sha256','freeze_sha256','initialization_predecessor_sha256','prerequisite_source_sha256','successor_cli_sha256']);
    m4c_expect($actual['run_id']===$source['run_id']&&$actual['source_sha']===$source['source_sha']&&$actual['container_id']===$created['id']
        &&is_int($actual['entry_namespace_pid'])&&$actual['entry_namespace_pid']>0&&$actual['entry_sha256']===$binding['entry_sha256']
        &&$actual['cli_sha256']===$binding['cli_sha256']&&$actual['source_files']===15&&$actual['binding_sha256']===$hashes[$names[1]]
        &&$actual['native_created_sha256']===$hashes[$names[0]]&&$actual['freeze_sha256']===$source['freeze_sha256']
        &&$actual['initialization_predecessor_sha256']===$binding['initialization_predecessor_sha256']
        &&$actual['successor_cli_sha256']===$binding['successor_cli_sha256'],'M4C_EARLY_REAL_THIRTEEN_KEY_PARENT_BINDING');
    $prerequisites=[];foreach(['help/migrations/marketplace-ms1.php','help/migrations/marketplace-ms4.php','help/migrations/marketplace-ms5-payment.php']as$path)$prerequisites[$path]=$sourceHashes[$path];
    m4c_expect($actual['prerequisite_source_sha256']===$prerequisites,'M4C_EARLY_ORIGINAL_PREREQUISITE_SOURCES');
    m4c_expect(is_array($result['children'])&&array_is_list($result['children'])&&count($result['children'])===4
        &&is_array($result['successor_children'])&&array_is_list($result['successor_children'])&&count($result['successor_children'])===12,
        'M4C_EARLY_FOUR_AND_TWELVE_NATIVE_CHILDREN');
    $pids=[];$counts=[];
    foreach(['fulfillment','aftersale','aftersale_menus','review_menus']as$kind){$modes=in_array($kind,['fulfillment','aftersale'],true)?['check','apply-structure','check','apply-structure']:['check','apply','check','apply'];$counts[$kind]=[];
        foreach($modes as$index=>$mode){$offset=array_search($kind,['aftersale','aftersale_menus','review_menus'],true);
            $child=$kind==='fulfillment'?$result['children'][$index]:$result['successor_children'][$offset*4+$index];
            $keys=['mode','pid','native_exit','proc_close_exit','same_handle_terminal','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','capture_error_class','result'];
            if($kind!=='fulfillment')array_push($keys,'script','script_sha256');m4c_entry_keys($child,$keys);m4c_entry_native_child($child);
            m4c_expect($child['mode']===$mode&&!isset($pids[$child['pid']]),'M4C_EARLY_DISTINCT_NATIVE_CHILD_IDENTITIES');$pids[$child['pid']]=true;
            $counts[$kind][$mode]=($counts[$kind][$mode]??0)+1;$answer=$child['result'];$script=$kind==='fulfillment'?'help/migrations/marketplace-ms4-fulfillment.php':$scripts[$kind];
            if($kind!=='fulfillment')m4c_expect($child['script']===$script&&$child['script_sha256']===$sourceHashes[$script],'M4C_EARLY_ACTUAL_SUCCESSOR_CHILD_SOURCE');
            m4c_expect(is_array($answer)&&$answer['mode']===$mode&&$answer['state']===($index===0?'applicable':'completed')
                &&$answer['changed']===($index===1)&&$answer['issues']===[],'M4C_EARLY_EXACT_NORMAL_CHILD_TRANSITIONS');
            $stdout=json_encode($answer,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            m4c_expect(strlen($stdout)===$child['stdout_bytes']&&hash('sha256',$stdout)===$child['stdout_sha256'],
                'M4C_EARLY_REAL_CLI_SUCCESS_EMIT_FULL_STDOUT_BYTES_AND_SHA');
            if(in_array($kind,['fulfillment','aftersale'],true)){
                $keys=['mode','state','native_exit','changed','applied_steps','pending_steps','pending_steps_known','issues','failure_phase','marker','source_sha256','scope','prerequisite_source_sha256','writer','acceptance'];
                if($kind==='aftersale')array_push($keys,'structure_stage','structure_step_count');m4c_entry_keys($answer,$keys);
                m4c_expect($answer['native_exit']===0&&$answer['pending_steps_known']===true&&$answer['failure_phase']===null&&$answer['source_sha256']===$sourceHashes[$script]
                    &&$answer['marker']===($kind==='fulfillment'?'marketplace-ms4-fulfillment-v1':'marketplace-ms4-aftersale-v1')
                    &&$answer['scope']===($kind==='fulfillment'?'MS4_FULFILLMENT_STRUCTURE_ONLY':'MS4_AFTERSALE_STRUCTURE_ONLY')
                    &&$answer['prerequisite_source_sha256']===($kind==='fulfillment'?$prerequisites:$prerequisites+[
                        'help/migrations/marketplace-ms4-fulfillment.php'=>$sourceHashes['help/migrations/marketplace-ms4-fulfillment.php'],
                        'crmeb/public/install/crmeb.sql'=>$sourceHashes['crmeb/public/install/crmeb.sql']])
                    &&$answer['writer']==='NOT RUN'&&$answer['acceptance']==='NOT RUN','M4C_EARLY_ORIGINAL_STRUCTURE_CHILD_WIRE');
                if($kind==='fulfillment'){$steps=['marketplace_fulfillment_operation','marketplace_fulfillment_range'];}
                else{$steps=['create.marketplace_aftersale_action','refund.column.marketplace_root_order_id','refund.column.marketplace_payment_attempt_id',
                    'refund.column.marketplace_kind','refund.column.marketplace_state','refund.column.marketplace_physical_state','refund.column.marketplace_finance_state',
                    'refund.column.marketplace_apply_action_id','refund.column.marketplace_last_action_id','refund.index.idx_ms4a_refund_root','refund.index.uq_ms4a_refund_apply',
                    'refund.index.idx_ms4a_refund_last','refund.foreign.mm4a_refund_apply_fk','refund.foreign.mm4a_refund_last_fk','refund.check.mm4a_refund_v1',
                    'operation.column.aftersale_cut_id','operation.index.idx_fop_aftersale_cut','operation.foreign.mm4a_fop_cut_fk',
                    'operation.checks.replace_known_ids_and_add_cut','create.marketplace_aftersale_file'];
                    m4c_expect($answer['structure_stage']===($index===0?0:20)&&$answer['structure_step_count']===20,'M4C_EARLY_ACTUAL_STAGE_TWENTY');}
                m4c_expect($answer['pending_steps']===($index===0?$steps:[])&&$answer['applied_steps']===($index===1?$steps:[]),'M4C_EARLY_EXACT_APPLIED_PENDING_STEPS');
            }else{
                $keys=['mode','state','changed','tables','menu_ids','issues','source_binding','authorization_cache_producer','roles_modified'];if($mode==='apply')$keys[]='created_menu_ids';m4c_entry_keys($answer,$keys);
                $map=$result['menu_leaves'][$kind];m4c_expect($answer['tables']===[]&&$answer['menu_ids']===($index===0?[]:$map)
                    &&($mode!=='apply'||$answer['created_menu_ids']===($index===1?$map:[]))&&$answer['roles_modified']===false
                    &&$answer['authorization_cache_producer']==='fresh_scope_for_request_exact_all_auth_and_current_roles'
                    &&$answer['source_binding']===['entry_sha256'=>$sourceHashes[$script],'ms1_sha256'=>$sourceHashes['help/migrations/marketplace-ms1.php']],
                    'M4C_EARLY_EXACT_MENU_NATIVE_MAP_NO_GRANT');}
        }}
    m4c_expect($result['actual_command_counts']===$counts,'M4C_EARLY_ACTUAL_SIXTEEN_COMMAND_COUNTS');
    m4c_entry_keys($result['menu_leaves'],['aftersale_menus','review_menus']);$ids=[];
    $permissions=['aftersale_menus'=>['marketplace-aftersales-list','marketplace-aftersales-read','marketplace-aftersales-approve','marketplace-aftersales-reject',
        'marketplace-aftersales-return-accept','marketplace-aftersale-files-ticket','marketplace-aftersale-files-read'],
        'review_menus'=>['marketplace-reviews-list','marketplace-reviews-read','marketplace-reviews-moderate']];
    foreach($permissions as$kind=>$keys){m4c_entry_keys($result['menu_leaves'][$kind],$keys);foreach($result['menu_leaves'][$kind]as$id){
        m4c_expect(is_int($id)&&$id>0&&$id<=65535&&!isset($ids[$id]),'M4C_EARLY_TEN_REAL_DISTINCT_MENU_PKS');$ids[$id]=true;}}
    m4c_entry_early_snapshots($result);
    foreach($names as$name)m4c_expect(m4c_entry_hash($base.$name)===$hashes[$name],'M4C_EARLY_FOUR_ORIGINAL_BYTES_UNCHANGED');
    return ['hashes'=>$hashes,'result'=>$result,'binding'=>$binding,'created'=>$created,'terminal'=>$terminal];
}

function m4c_entry_early_paid_unstarted(array $source,string $stage): void
{
    m4c_expect(in_array($stage,['files-bootstrap','customer-prepare'],true),'M4C_EARLY_FIXED_UNSTARTED_STAGE');
    $base='/run/audit/evidence/';$canonical=m4c_entry_read($base.'paid-producer-created.json');$fresh=m4c_entry_read($base.$stage.'-paid-unstarted.json');
    $keys=['id','image','hostname','user','stop_signal','labels','mounts','tmpfs','read_only','privileged','network_mode','status','command','entrypoint',
        'workdir','restart_count','pid','exit_code','networks','ports','port_bindings','cap_drop','security_options','started_at','finished_at','created_at'];
    m4c_entry_keys($canonical,[...$keys,'run_id','source_sha','proof_name']);m4c_entry_keys($fresh,$keys);
    m4c_expect($canonical['run_id']===$source['run_id']&&$canonical['source_sha']===$source['source_sha']&&$canonical['proof_name']==='paid-producer'
        &&$canonical['image']===M4C_IMAGE&&$canonical['status']==='created'&&$canonical['pid']===0&&$canonical['restart_count']===0
        &&$canonical['exit_code']===0&&is_string($canonical['started_at'])&&is_string($canonical['finished_at'])
        &&preg_match('/\A0001-01-01T00:00:00(?:\.0{1,9})?Z\z/D',$canonical['started_at'])===1
        &&preg_match('/\A0001-01-01T00:00:00(?:\.0{1,9})?Z\z/D',$canonical['finished_at'])===1
        &&$canonical['hostname']===substr($canonical['id'],0,12)&&$canonical['labels']['com.docker.compose.service']==='audit-ms5-paid-producer'
        &&$canonical['labels']['tianli.audit.run']===$source['run_id']&&$canonical['labels']['com.docker.compose.project']===$source['context']['project']
        &&$canonical['read_only']===true&&$canonical['privileged']===false&&$canonical['entrypoint']===['php']
        &&$canonical['command']===['help/audit-regressions/isolated/ms5-paid-consumer-normal.php','produce']&&$canonical['workdir']==='/workspace',
        'M4C_EARLY_CANONICAL_PAID_NOT_STARTED');
    $native=array_diff_key($canonical,array_flip(['run_id','source_sha','proof_name']));m4c_entry_same($fresh,$native);
    $manifest=audit_manifest();m4c_expect($manifest['services']['audit-ms5-paid-producer']['id']===$canonical['id']
        &&$manifest['services']['audit-ms5-paid-producer']['image_id']===M4C_IMAGE,'M4C_EARLY_SAME_CREATED_PAID_CURRENT_SLOT');
}

function m4c_entry_early_quote(string $name): string
{
    m4c_expect(preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$name)===1,'M4C_EARLY_FIXED_SQL_IDENTIFIER');return '`'.$name.'`';
}
function m4c_entry_early_metadata(PDO $db,string $table): array
{
    $args=[$table];$tables=m4cb_rows($db,'SELECT TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',$args);
    m4c_expect(count($tables)===1&&$tables[0]['TABLE_TYPE']==='BASE TABLE','M4C_EARLY_COMPLETE_NATIVE_TABLE_METADATA');$columns=[];$indexes=[];
    foreach(m4cb_rows($db,'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',$args)as$row)$columns[$row['COLUMN_NAME']]=$row;
    foreach(m4cb_rows($db,'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',$args)as$row)$indexes[$row['INDEX_NAME']][]=$row;
    ksort($indexes,SORT_STRING);
    return ['table'=>$tables[0],'columns'=>$columns,'indexes'=>$indexes,
        'constraints'=>m4cb_rows($db,'SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY BINARY CONSTRAINT_NAME',$args),
        'checks'=>m4cb_rows($db,"SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME",$args),
        'foreign'=>m4cb_rows($db,'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.POSITION_IN_UNIQUE_CONSTRAINT,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UNIQUE_CONSTRAINT_SCHEMA,r.UNIQUE_CONSTRAINT_NAME,r.UPDATE_RULE,r.DELETE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION',$args),
        'triggers'=>m4cb_rows($db,'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME',$args)];
}
function m4c_entry_early_table(PDO $db,string $name,array $sealed): array
{
    m4c_entry_keys($sealed,['ddl_sha256','projected_columns','projection','metadata_sha256','metadata_serialized_base64']);
    $ddl=$db->query('SHOW CREATE TABLE '.m4c_entry_early_quote($name))->fetch(PDO::FETCH_NUM);
    m4c_expect(is_array($ddl)&&is_string($ddl[1]??null)&&$sealed['ddl_sha256']===hash('sha256',$ddl[1]),'M4C_EARLY_REAL_DDL_HASH_RETAINED');
    $metadata=m4c_entry_early_metadata($db,$name);$serialized=serialize($metadata);
    m4c_expect($sealed['metadata_sha256']===hash('sha256',$serialized)&&$sealed['metadata_serialized_base64']===base64_encode($serialized)
        &&$sealed['projected_columns']===array_keys($metadata['columns']),'M4C_EARLY_COMPLETE_NATIVE_METADATA_RETAINED');
    $columns=$sealed['projected_columns'];m4c_expect($columns!==[],'M4C_EARLY_COMPLETE_PROJECTION_COLUMNS');
    $statement=$db->query('SELECT '.implode(',',array_map('m4c_entry_early_quote',$columns)).' FROM '.m4c_entry_early_quote($name));
    m4c_expect($statement instanceof PDOStatement,'M4C_EARLY_NATIVE_PROJECTION_READ');$hashes=[];
    while(($row=$statement->fetch(PDO::FETCH_ASSOC))!==false)$hashes[]=hash('sha256',serialize($row));sort($hashes,SORT_STRING);
    $projection=['encoding'=>'php-serialize-row-sha256-sorted-multiset-v1','rows'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
    m4c_expect($projection===$sealed['projection'],'M4C_EARLY_ALL_NATIVE_ROW_VALUES_TYPES_AND_MULTIPLICITY_RETAINED');return $projection;
}
/** Fixed preparation stages; neither stage grants business authority or replays a migration. */
function m4c_entry_early_preserved(array $history,string $stage): array
{
    m4c_expect(in_array($stage,['files-bootstrap','files-bootstrap-completed','customer-prepare'],true),'M4C_EARLY_FIXED_PRESERVATION_STAGE');audit_guard_assert();
    $sealed=$history['result']['snapshots']['review_menus'];
    m4c_entry_keys($sealed,['names','tables','redis','modes','metadata_observer_modes','native_snapshot_sha256']);
    $db=audit_db();m4c_expect(!$db->inTransaction()&&$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false)
        &&$db->query('SELECT CURRENT_USER()')->fetchColumn()==='audit_app@%'
        &&$db->query('SELECT DATABASE()')->fetchColumn()==='audit_auth','M4C_EARLY_ORIGINAL_APP_READ_OBSERVER');
    m4c_expect($db->exec('SET SESSION information_schema_stats_expiry=0')===0,'M4C_EARLY_ONLY_OBSERVER_METADATA_EXPIRY');
    $observer=m4cb_rows($db,'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb,@@SESSION.information_schema_stats_expiry AS session_stats_expiry,@@GLOBAL.information_schema_stats_expiry AS global_stats_expiry');
    $modes=m4cb_rows($db,'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb');
    m4c_expect($observer===$sealed['metadata_observer_modes']&&$modes===$sealed['modes'],'M4C_EARLY_ALL_ORIGINAL_OBSERVER_AND_SQL_MODES');
    $names=$db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);sort($names,SORT_STRING);
    m4c_expect($names===$sealed['names']&&array_keys($sealed['tables'])===$names,'M4C_EARLY_ACTUAL_COMPLETE_TABLE_NAMES');
    $targets=$stage==='files-bootstrap'?$names:['eb_marketplace_fulfillment_operation','eb_marketplace_fulfillment_range',
        'eb_marketplace_aftersale_action','eb_marketplace_aftersale_file','eb_store_order_refund','eb_system_menus'];$observed=[];
    foreach($targets as$name){m4c_expect(isset($sealed['tables'][$name]),'M4C_EARLY_ACTUAL_PROTECTED_TABLE');$observed[$name]=m4c_entry_early_table($db,$name,$sealed['tables'][$name]);}
    if($stage==='files-bootstrap'){$snapshot=audit_snapshot(['tables'=>[],'redis'=>['*']]);$redis=audit_redis();$actual=[];
        foreach($snapshot['redis']['*']as$key=>$hash){$expires=$redis->rawCommand('PEXPIRETIME',$key);$keyHash=hash('sha256',(string)$key);
            m4c_expect(is_int($expires)&&($expires===-1||$expires>0)&&!isset($actual[$keyHash]),'M4C_EARLY_ACTUAL_ABSOLUTE_REDIS_EXPIRY');$actual[$keyHash]=['sha256'=>$hash,'expires'=>$expires];}
        ksort($actual,SORT_STRING);m4c_expect($actual===$sealed['redis'],'M4C_EARLY_BEFORE_APP_COMPLETE_REDIS_RETAINED');}
    $again=m4cb_rows($db,'SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode,@@SESSION.innodb_strict_mode AS session_innodb,@@GLOBAL.innodb_strict_mode AS global_innodb,@@SESSION.information_schema_stats_expiry AS session_stats_expiry,@@GLOBAL.information_schema_stats_expiry AS global_stats_expiry');
    m4c_expect($again===$observer,'M4C_EARLY_NATIVE_OBSERVER_SIX_MODES_RETAINED');audit_guard_assert();
    return ['stage'=>$stage,'early_result_sha256'=>$history['hashes']['ms4-aftersale-early-migration-normal.json'],
        'metadata_observer_modes'=>$observer,'protected_native_projections'=>$observed];
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
    $roles=array_keys($manifest['services']);sort($roles,SORT_STRING);$wanted=[$rule['role'],'audit-mysql','audit-redis'];if($mode==='prepare')$wanted[]='audit-ms5-paid-producer';sort($wanted,SORT_STRING);
    m4c_expect($roles===$wanted&&is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1
        &&$manifest['services'][$rule['role']]['id']===$cid&&gethostname()===substr($cid,0,12),'M4C_FIXED_PREPARE_FOUR_OR_VERIFY_THREE_ROLE_IDENTITIES');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    $sortedExtra=M4C_EXTRA;sort($sortedExtra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$sortedExtra,'M4C_FROZEN_CUSTOMER_SOURCE_CONTRACT');
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','prerequisites_sha256']);
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
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],true],
        '/workspace/crmeb/public/install/crmeb.sql'=>[$context['repo_dir'].'/crmeb/public/install/crmeb.sql',false]];
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
    $values=m4c_entry_environment($binding,$context,$rule['proof']);
    audit_guard_assert();
    $source=$binding+['mode'=>$mode,'rule'=>$rule,'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'context'=>$context,'freeze'=>$freeze,'source_binding_sha256'=>hash_file('sha256',$evidence.$rule['binding']),
        'environment_sha256'=>hash_file('sha256',$evidence.$rule['environment'])];
    m4c_entry_full_source($source,true);
    m4c_entry_ms5_prerequisites($source);
    return $source;
}
function m4c_entry_public_materials(array $source): void
{
    $public='/workspace/crmeb/public';$install=$public.'/install';$runtime='/workspace/crmeb/runtime';
    m4c_expect(is_dir($public)&&!is_link($public)&&realpath($public)===$public
        &&scandir($public)===['.','..','install'],'M4C_OWN_PUBLIC_INSTALL_ONLY');
    m4c_expect(is_dir($install)&&!is_link($install)&&realpath($install)===$install
        &&scandir($install)===['.','..','crmeb.sql'],'M4C_OWN_INSTALL_SQL_ONLY');
    m4c_expect(is_dir($runtime)&&!is_link($runtime)&&count(scandir($runtime))===2,'M4C_OWN_EMPTY_TMPFS');
    foreach([$public,$runtime]as$directory)m4c_expect(chmod($directory,0700),'M4C_OWN_TMPFS_MODE');
    $rows=$source['freeze']['driver_source_files'];m4c_expect(is_array($rows)&&array_is_list($rows),'M4C_PUBLIC_FROZEN_DRIVER_SOURCE_LIST');
    $matches=array_values(array_filter($rows,static fn(array $row):bool=>$row['path']==='crmeb/public/install/crmeb.sql'));
    m4c_expect(count($matches)===1,'M4C_PUBLIC_UNIQUE_FROZEN_INSTALL_SOURCE');$row=$matches[0];
    m4c_entry_keys($row,['path','bytes','sha256']);
    m4c_expect(is_int($row['bytes'])&&$row['bytes']>=0&&is_string($row['sha256'])
        &&preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])===1,'M4C_PUBLIC_FROZEN_INSTALL_DESCRIPTOR');
    foreach(['/source','/workspace']as$root){$path=$root.'/'.$row['path'];clearstatcache(true,$path);
        m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path&&filesize($path)===$row['bytes']
            &&m4c_entry_hash($path)===$row['sha256']&&!is_writable($path),'M4C_PUBLIC_DUAL_ORIGINAL_INSTALL_BYTES_READONLY');}
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
    $early=null;
    if($mode==='prepare'){$early=m4c_entry_early_history($source);m4c_entry_early_paid_unstarted($source,'customer-prepare');
        m4c_entry_early_preserved($early,'customer-prepare');}
    m4c_entry_public_materials($source);m4c_entry_full_source($source);$GLOBALS['m4c_phase']='app';m4c_app($mode,$source);
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
    $GLOBALS['m4c_child_proof']=$childProof;
    if($failure!==null){[$GLOBALS['m4c_phase'],$GLOBALS['m4c_failed_assertion'],$GLOBALS['m4c_warning']]=$failureMeta;throw $failure;}
    $GLOBALS['m4c_phase']='final_source';audit_guard_assert();m4c_entry_full_source($source);
    m4c_entry_ms5_prerequisites($source);
    if($early!==null){$currentEarly=m4c_entry_early_history($source);m4c_entry_same($currentEarly['hashes'],$early['hashes']);
        m4c_entry_early_preserved($currentEarly,'customer-prepare');}
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
            'error_location'=>['file'=>basename($error->getFile()),'line'=>$error->getLine()],
            'code'=>is_string($code)&&preg_match('/\AM4C_[A-Z0-9_]+\z/D',$code)===1?$code:'M4C_UNCLASSIFIED',
            'native_exit'=>1,'actual_customer_HTTP'=>'INCOMPLETE','browser_visual_acceptance'=>'NOT RUN',
            'http_calls_completed'=>(int)($GLOBALS['m4c_http_call_count']??0),
            'child'=>$GLOBALS['m4c_child_proof']??null];
        if(is_array($source)){foreach(['run_id','source_sha','container_id','freeze_sha256'] as $key)$safe[$key]=$source[$key];
            if(isset($GLOBALS['m4c_warning']))$safe['warning']=$GLOBALS['m4c_warning'];
            try{m4c_entry_publish('/run/audit/evidence/'.$source['rule']['proof'].'-failure.json',$safe);}catch(Throwable $ignored){}}
        echo json_encode($safe,JSON_THROW_ON_ERROR)."\n";exit(1);}
}
