<?php
declare(strict_types=1);

/** Finite pre-UI helpers only. The imported customer entry's main guard does not execute. */
require_once '/workspace/help/audit-regressions/isolated/ms4-customer-h5.php';

const M4CH_BOOTSTRAP='/run/ms4-prep/ms4-h5-bootstrap.php';
const M4CH_ORIGINAL_ARTIFACTS=['README.md','compose.yml','ms4-host.py','ms4-initialize.php',
    'seed-source-inverse.json','interface-v1.json','source-derivation.json','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py'];
const M4CH_BOOTSTRAP_REQUIRED_ARTIFACTS=['compose.h5.yml','inputs/language.json','inputs/theme.json',
    'manifests/h5-dist.json','manifests/inputs.json','manifests/public-source.json',
    'ms4-h5-bootstrap.php','ms4-h5-public-copy.php','ms4-order.png'];

function m4ch_bootstrap_files(string $root,array $rows): void
{
    m4c_expect(array_is_list($rows)&&$rows!==[],'M4C_H5_BOOTSTRAP_SOURCE_LIST');$sorted=$rows;
    usort($sorted,static fn(array$a,array$b):int=>strcmp($a['path'],$b['path']));m4ch_entry_rows($sorted);
    foreach($rows as$row){$path=$root.'/'.$row['path'];clearstatcache(true,$path);
        m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path&&filesize($path)===$row['bytes']
            &&hash_file('sha256',$path)===$row['sha256'],'M4C_H5_BOOTSTRAP_ALL_FROZEN_SOURCE_BYTES');}
}
function m4ch_bootstrap_materials(array $source): array
{
    $materials=$source['h5_materials'];m4c_entry_keys($materials,['runtime_origin','public_source','dist','inputs','fixture_png']);
    m4c_expect(is_string($materials['runtime_origin'])
        &&preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D',$materials['runtime_origin'],$port)===1
        &&(int)$port[1]<=65535,'M4C_H5_BOOTSTRAP_FROZEN_RUNTIME_ORIGIN');$trees=[];
    foreach(M4CH_MANIFESTS as$name=>$path){$value=m4ch_entry_json($materials[$name],$path);
        if($name==='inputs'){m4c_entry_keys($value,['schema','language','theme']);m4c_expect($value['schema']===1,'M4C_H5_BOOTSTRAP_INPUT_MANIFEST_SCHEMA');
            foreach(M4CH_INPUTS as$key=>$descriptor){m4c_expect($value[$key]===$descriptor,'M4C_H5_BOOTSTRAP_APPROVED_INPUT_FILE_DESCRIPTOR');
                m4ch_entry_json($descriptor,$descriptor['path']);}continue;}
        m4c_entry_keys($value,['schema','files','directories']);m4c_expect($value['schema']===1,'M4C_H5_BOOTSTRAP_TREE_MANIFEST_SCHEMA');
        m4ch_entry_rows($value['files']);m4ch_entry_directories($value['directories']);$trees[$name]=$value;}
    $png=$materials['fixture_png'];m4c_entry_keys($png,['path','bytes','sha256']);
    m4c_expect($png['path']==='/run/ms4-prep/ms4-order.png'&&is_int($png['bytes'])&&$png['bytes']>0&&$png['bytes']<=2097152
        &&is_string($png['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$png['sha256'])===1,'M4C_H5_BOOTSTRAP_FIXED_PUBLIC_PNG_DESCRIPTOR');
    m4ch_bootstrap_files('/run/ms4-prep',[['path'=>'ms4-order.png','bytes'=>$png['bytes'],'sha256'=>$png['sha256']]]);
    m4ch_entry_tree('/source/crmeb/public',$trees['public_source']['files'],$trees['public_source']['directories']);
    if($source['mode']==='copy')m4ch_entry_tree('/run/ms4-h5-dist',$trees['dist']['files'],$trees['dist']['directories']);
    return ['public_source_files'=>$trees['public_source']['files'],'public_source_directories'=>$trees['public_source']['directories'],
        'h5_dist_files'=>$trees['dist']['files'],'h5_dist_directories'=>$trees['dist']['directories'],
        'synthetic_png'=>['path'=>'ms4-order.png','bytes'=>$png['bytes'],'sha256'=>$png['sha256']]];
}
/** No App is created here. The host independently gates the actual global resource inventory. */
function m4ch_bootstrap_source(string $phase): array
{
    if($phase==='register-final')return m4ch_bootstrap_final_source();
    $proof=getenv('MS4_PROOF_NAME');
    $phases=['copy'=>['proof'=>'customer-h5-public-copy','role'=>'audit-ms4-customer-h5-public-copy',
        'command'=>['/run/ms4-prep/ms4-h5-public-copy.php','copy']],
        'register'=>['proof'=>$proof,'role'=>'audit-ms4-customer-h5-register','command'=>[M4CH_BOOTSTRAP,'register']],
        'files-bootstrap'=>['proof'=>'customer-h5-files','role'=>'audit-ms4-customer-h5-files','command'=>[M4CH_BOOTSTRAP,'files-bootstrap']]];
    m4c_expect(isset($phases[$phase])&&($phase!=='register'||in_array($proof,
        ['register-customer-h5-prepare','register-customer-h5-files'],true)),'M4C_H5_BOOTSTRAP_FINITE_PHASE_PROOF');$rule=$phases[$phase];
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===$rule['command'][0]&&($_SERVER['argv']??[])===$rule['command']
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit'
        &&getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'&&$proof===$rule['proof'],
        'M4C_H5_BOOTSTRAP_ACTUAL_NATIVE_ENTRY');
    $run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');$evidence='/run/audit/evidence/';
    $context=m4c_entry_read('/run/audit/actual-run-context.json');$freeze=m4c_entry_read('/run/audit/preparation-source-binding.json');
    $created=m4c_entry_read($evidence.$proof.'-created.json');$bindingPath=$evidence.$proof.'-source-binding.json';
    $binding=m4c_entry_read($bindingPath);$manifest=audit_manifest();$cid=$created['id']??null;
    m4c_expect(preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run)===1&&preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha)===1
        &&$context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$manifest['run_id']===$run&&$manifest['commit_sha']===$sha
        &&$manifest['project']===$context['project']&&$manifest['isolation']==='ISO-FULL'
        &&$manifest['guard_mode']==='read-only'&&$manifest['network_internal']===true,'M4C_H5_BOOTSTRAP_SAME_REAL_RUN');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');$extra=M4C_EXTRA;sort($extra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$extra,
        'M4C_H5_BOOTSTRAP_ORIGINAL_SOURCE_CONTRACT');
    m4c_entry_keys($binding,['schema','scope','phase','proof_name','run_id','source_sha','container_id','native_created_sha256',
        'freeze_sha256','requested_repo_source','requested_run_source','requested_prep_source','entry_sha256',
        'source_files','h5_source_files','artifact_files','h5_artifact_files','environment','public_copy','prepare_result','registration_inputs','prerequisites_sha256','canonical_paid']);
    m4c_expect($binding['schema']===1&&$binding['scope']==='MS4_CUSTOMER_H5_BOOTSTRAP_MS5_SOURCE'&&$binding['phase']===$phase
        &&$binding['proof_name']===$proof&&$binding['run_id']===$run&&$binding['source_sha']===$sha&&$binding['container_id']===$cid
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.$proof.'-created.json')&&$binding['freeze_sha256']===$freezeHash
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['entry_sha256']===hash_file('sha256',$rule['command'][0])
        &&$binding['source_files']===$freeze['customer_source_files']&&$binding['h5_source_files']===$freeze['h5_source_files']
        &&$binding['artifact_files']===$freeze['artifact_files']&&$binding['h5_artifact_files']===$freeze['h5_artifact_files'],
        'M4C_H5_BOOTSTRAP_ORIGINAL_CREATED_SOURCE_BINDING');
    m4c_expect(is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1&&gethostname()===substr($cid,0,12)
        &&$created['image']===M4C_IMAGE&&$created['run_id']===$run&&$created['source_sha']===$sha&&$created['proof_name']===$proof
        &&$created['hostname']===gethostname()&&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0
        &&$created['read_only']===true&&$created['privileged']===false&&$created['entrypoint']===['php']
        &&$created['command']===$rule['command']&&$created['workdir']==='/workspace'&&$created['cap_drop']===['ALL']
        &&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true),
        'M4C_H5_BOOTSTRAP_REAL_CREATED_PHP_ROLE');
    m4c_expect($created['labels']['tianli.audit']==='1'&&$created['labels']['tianli.audit.run']===$run
        &&$created['labels']['com.docker.compose.project']===$context['project']
        &&$created['labels']['com.docker.compose.service']===$rule['role'],'M4C_H5_BOOTSTRAP_ORIGINAL_NATIVE_LABELS');
    $network=m4c_entry_read('/run/audit/network.json');
    m4c_expect($network===$context['initial_network']&&$network['Internal']===true&&$network['Id']===$context['network_id']
        &&$network['Name']===$context['project'].'_isolation'&&$created['network_mode']===$network['Name']
        &&array_keys($created['networks'])===[$network['Name']]
        &&in_array($created['networks'][$network['Name']]['NetworkID'],['',$network['Id']],true)
        &&in_array($created['port_bindings'],[null,[]],true),'M4C_H5_BOOTSTRAP_ONE_ORIGINAL_INTERNAL_NETWORK');
    m4c_expect($created['ports']===null||is_array($created['ports']),'M4C_H5_BOOTSTRAP_NATIVE_PORTS_OBJECT');
    foreach($created['ports']??[]as$mapping)m4c_expect(in_array($mapping,[null,[]],true),'M4C_H5_BOOTSTRAP_NO_PUBLISHED_PORT');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],$phase!=='files-bootstrap']];
    $volumes=['/run/audit-app'=>['app-secrets',false]];
    if($phase==='copy'){
        m4c_expect(is_string($freeze['h5_dist_source'])&&$freeze['h5_dist_source']!=='','M4C_H5_BOOTSTRAP_REAL_FROZEN_DIST_HOST_SOURCE');
        $binds['/run/ms4-h5-dist']=[$freeze['h5_dist_source'],false];
        $volumes+=['/run/audit-fixtures'=>['fixture-secrets',false],'/workspace/crmeb/public'=>['http-public',true],
            '/workspace/crmeb/runtime'=>['http-runtime',true],'/run/audit-http-control'=>['http-control',true],
            '/run/audit-http-browser'=>['http-browser-secrets',true]];
    }elseif($phase==='files-bootstrap')$volumes+=['/run/audit-bootstrap'=>['bootstrap-secrets',false],
        '/run/audit-fixtures'=>['fixture-secrets',false],'/workspace/crmeb/public'=>['http-public',false],
        '/workspace/crmeb/runtime'=>['http-runtime',true],'/run/audit-http-control'=>['http-control',true]];
    $mounts=[];$tmpSeen=false;
    foreach($created['mounts']as$mount){if($mount['Type']!=='tmpfs'){$mounts[]=$mount;continue;}
        m4c_expect($mount['Destination']==='/tmp'&&$mount['RW']===true&&!$tmpSeen,'M4C_H5_BOOTSTRAP_ONLY_DECLARED_TMPFS');$tmpSeen=true;}
    m4c_expect(count($mounts)===count($binds)+count($volumes),'M4C_H5_BOOTSTRAP_CLOSED_NATIVE_MOUNTS');
    foreach(array_merge($binds,$volumes)as$target=>$wanted){$matches=array_values(array_filter($mounts,
        static fn(array$row):bool=>$row['Destination']===$target));m4c_expect(count($matches)===1,'M4C_H5_BOOTSTRAP_ONE_EXACT_MOUNT');
        $mount=$matches[0];m4c_expect($mount['RW']===$wanted[1],'M4C_H5_BOOTSTRAP_EXACT_MOUNT_ACCESS');
        if(isset($binds[$target]))m4c_expect($mount['Type']==='bind'&&audit_canonical_host_bind_source($mount['Source'])
            ===audit_canonical_host_bind_source($wanted[0]),'M4C_H5_BOOTSTRAP_REAL_HOST_BIND_SOURCE');
        else m4c_expect($mount['Type']==='volume'&&$mount['Name']===$context['project'].'_'.$wanted[0],
            'M4C_H5_BOOTSTRAP_ORIGINAL_NATIVE_VOLUME');}
    m4c_expect(is_array($created['tmpfs'])&&array_keys($created['tmpfs'])===['/tmp']&&is_string($created['tmpfs']['/tmp'])
        &&in_array('rw',explode(',',$created['tmpfs']['/tmp']),true),'M4C_H5_BOOTSTRAP_EXACT_TMPFS_CONFIG');
    m4c_expect(!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/public/install.lock'),'M4C_H5_BOOTSTRAP_NO_HOST_RUNTIME_CONFIGURATION');
    $values=$binding['environment'];m4c_expect(is_array($values),'M4C_H5_BOOTSTRAP_PUBLIC_ENVIRONMENT_OBJECT');
    m4c_entry_keys($values,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED','PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER']);
    $initializer=m4c_entry_read($evidence.'initializer-result.json');
    m4c_expect($values['APP_ENV']==='testing'&&$values['PHP_MARKETPLACE_ENABLED']==='true'&&$values['PHP_MARKETPLACE_TRADE_ENABLED']==='true'
        &&$values['PHP_MARKETPLACE_PAYMENT_TEST_ENABLED']==='true'&&$values['PHP_CACHE_DRIVER']==='redis'&&is_string($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])
        &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])===1
        &&bccomp($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'4294967295',0)<=0
        &&$initializer['run_id']===$run&&$initializer['source_sha']===$sha&&$initializer['native_exit']===0
        &&$initializer['status']==='VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY'
        &&$initializer['actual_self_mer_id']===(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],
        'M4C_H5_BOOTSTRAP_ORIGINAL_REAL_ENABLED_ENVIRONMENT');
    foreach($values as$key=>$value)m4c_expect(getenv($key)===$value,'M4C_H5_BOOTSTRAP_ACTUAL_PROCESS_ENVIRONMENT');
    m4c_entry_native_environment($values,m4c_entry_read($evidence.$proof.'-environment-native.json'));
    $source=$binding+['mode'=>$phase,'rule'=>$rule,'context'=>$context,'freeze'=>$freeze,'h5_materials'=>$freeze['h5_materials'],
        'source_binding_sha256'=>hash_file('sha256',$bindingPath),'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID']];
    m4c_entry_full_source($source,$phase==='copy');
    $h5=m4ch_entry_rows($source['h5_source_files']);m4c_expect(array_column($h5,'path')===M4CH_SOURCE_FILES,
        'M4C_H5_BOOTSTRAP_EXACT_TWO_H5_PHP_FILES');
    foreach(['/source','/workspace']as$root){m4ch_bootstrap_files($root,$h5);
        if($phase==='copy'&&$root==='/workspace'){
            $workspaceDriver=array_values(array_filter($freeze['driver_source_files'],
                static fn(array$row):bool=>$row['path']!=='crmeb/public/install/crmeb.sql'));
            m4ch_bootstrap_files($root,$workspaceDriver);
        }else m4ch_bootstrap_files($root,$freeze['driver_source_files']);
        m4ch_bootstrap_files($root,$freeze['preparation_source_references']);}
    m4c_expect(array_column($source['artifact_files'],'path')===M4CH_ORIGINAL_ARTIFACTS,'M4C_H5_BOOTSTRAP_ORIGINAL_SEVEN_PREP_ARTIFACTS');
    m4ch_bootstrap_files('/run/ms4-prep',$source['artifact_files']);m4ch_bootstrap_files('/run/ms4-prep',$source['h5_artifact_files']);
    $h5Paths=array_column($source['h5_artifact_files'],'path');
    m4c_expect(array_intersect($h5Paths,M4CH_ORIGINAL_ARTIFACTS)===[]
        &&array_diff(M4CH_BOOTSTRAP_REQUIRED_ARTIFACTS,$h5Paths)===[],'M4C_H5_BOOTSTRAP_COMPLETE_DISTINCT_FROZEN_H5_ARTIFACTS');
    if($phase==='files-bootstrap')m4c_expect(is_array($source['public_copy'])&&is_array($source['prepare_result'])
        &&$source['registration_inputs']===null,'M4C_H5_BOOTSTRAP_FILES_ONLY_INPUT_BINDINGS');
    elseif($phase==='register')m4c_expect($source['public_copy']===null&&$source['prepare_result']===null&&is_array($source['registration_inputs']),
        'M4C_H5_BOOTSTRAP_REGISTER_ONLY_INPUT_BINDINGS');
    else m4c_expect($source['public_copy']===null&&$source['prepare_result']===null&&$source['registration_inputs']===null,
        'M4C_H5_BOOTSTRAP_COPY_HAS_NO_FUTURE_RECEIPTS');
    if($phase==='files-bootstrap'){$roles=array_keys($manifest['services']);sort($roles,SORT_STRING);$wanted=[$rule['role'],'audit-ms5-paid-producer','audit-mysql','audit-redis'];sort($wanted,SORT_STRING);
        m4c_expect($roles===$wanted&&$manifest['services'][$rule['role']]['id']===$cid,'M4C_H5_BOOTSTRAP_REGISTERED_CURRENT_THREE_ROLES');}
    m4ch_ms5_prerequisites($source,$phase==='register'
        ?($proof==='register-customer-h5-prepare'?'register-prepare':'register-files'):$phase);
    $source['copy_profile']=m4ch_bootstrap_materials($source);m4cb_guard_readonly();return $source;
}
/** Called exactly once by the guarded copier after source('copy'), before m6p_copy; never by register/files. */
function m4ch_bootstrap_volumes(array $source): void
{
    m4c_expect($source['mode']==='copy'&&$source['proof_name']==='customer-h5-public-copy','M4C_H5_BOOTSTRAP_COPY_OWNS_VOLUME_INITIALIZATION');
    foreach(['/workspace/crmeb/runtime','/run/audit-http-control','/run/audit-http-browser','/workspace/crmeb/public']as$path){
        m4c_expect(is_dir($path)&&!is_link($path)&&realpath($path)===$path&&count(scandir($path))===2&&chmod($path,0700),
            'M4C_H5_BOOTSTRAP_FOUR_FRESH_EMPTY_VOLUME_ROOTS');clearstatcache(true,$path);
        m4c_expect((fileperms($path)&0777)===0700&&fileowner($path)===posix_geteuid(),'M4C_H5_BOOTSTRAP_EMPTY_VOLUME_ACTUAL_OWNER_MODE');}
    foreach(['supervisor-requests','supervisor-responses','commands','captures','acks','screenshots']as$name){$path='/run/audit-http-control/'.$name;
        m4c_expect(!file_exists($path)&&!is_link($path)&&mkdir($path,0700),'M4C_H5_BOOTSTRAP_CREATE_ONLY_CONTROL_SUBDIRECTORY');clearstatcache(true,$path);
        m4c_expect(is_dir($path)&&!is_link($path)&&(fileperms($path)&0777)===0700&&fileowner($path)===posix_geteuid(),
            'M4C_H5_BOOTSTRAP_CONTROL_SUBDIRECTORY_ACTUAL_OWNER_MODE');}
}
function m4ch_bootstrap_register(array $source): array
{
    m4c_expect($source['mode']==='register','M4C_H5_BOOTSTRAP_REGISTER_ONLY_PHASE');
    $descriptors=$source['registration_inputs'];m4c_entry_keys($descriptors,['containers','volumes','volume_identities']);
    $paths=['containers'=>'/run/audit/containers.jsonl','volumes'=>'/run/audit/volumes.txt','volume_identities'=>'/run/audit/volumes.jsonl'];$raws=[];
    foreach($paths as$key=>$path){$descriptor=$descriptors[$key];m4c_expect(is_array($descriptor),'M4C_H5_BOOTSTRAP_REGISTRATION_DESCRIPTOR');
        m4c_entry_keys($descriptor,['path','bytes','sha256']);m4c_expect($descriptor['path']===$path&&is_int($descriptor['bytes'])
            &&$descriptor['bytes']>0&&$descriptor['bytes']<=16777216&&is_string($descriptor['sha256'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$descriptor['sha256'])===1&&is_file($path)&&!is_link($path)&&realpath($path)===$path,
            'M4C_H5_BOOTSTRAP_ACTUAL_REGISTRATION_INPUT_FILE');$raw=file_get_contents($path);
        m4c_expect(is_string($raw)&&strlen($raw)===$descriptor['bytes']&&hash('sha256',$raw)===$descriptor['sha256'],
            'M4C_H5_BOOTSTRAP_BOUND_REGISTRATION_INPUT_BYTES');$raws[$key]=$raw;}
    $targetProof=substr($source['proof_name'],strlen('register-'));$targetRole=$targetProof==='customer-h5-prepare'?M4CH_ROLE:'audit-ms4-customer-h5-files';
    $target=m4c_entry_read('/run/audit/evidence/'.$targetProof.'-created.json');$rows=[];
    foreach(explode("\n",trim($raws['containers']))as$line){$row=json_decode($line,true,128,JSON_THROW_ON_ERROR);
        m4c_expect(is_array($row),'M4C_H5_BOOTSTRAP_NATIVE_REGISTRATION_ROW');$role=$row['labels']['com.docker.compose.service'];
        m4c_expect(is_string($role)&&!isset($rows[$role])&&$row['labels']['tianli.audit.run']===$source['run_id']
            &&$row['labels']['com.docker.compose.project']===$source['context']['project'],'M4C_H5_BOOTSTRAP_UNIQUE_REGISTERED_NATIVE_ROLE');$rows[$role]=$row;}
    $roles=array_keys($rows);sort($roles,SORT_STRING);$wanted=[$targetRole,'audit-ms5-paid-producer','audit-mysql','audit-redis'];sort($wanted,SORT_STRING);
    m4c_expect($roles===$wanted&&$target['run_id']===$source['run_id']&&$target['source_sha']===$source['source_sha']
        &&$target['proof_name']===$targetProof&&$target['status']==='created'&&$target['pid']===0&&$target['restart_count']===0
        &&is_string($target['id'])&&preg_match('/\A[a-f0-9]{64}\z/D',$target['id'])===1&&$target['image']===M4C_IMAGE
        &&$target['labels']['com.docker.compose.service']===$targetRole&&$target['labels']['tianli.audit.run']===$source['run_id']
        &&$target['labels']['com.docker.compose.project']===$source['context']['project']
        &&$target['entrypoint']===['php']&&$target['command']===($targetProof==='customer-h5-prepare'
            ?[M4CH_ENTRY,'prepare']:[M4CH_BOOTSTRAP,'files-bootstrap'])
        &&$rows[$targetRole]['id']===$target['id']&&$target['id']!==$source['container_id']
        &&$rows['audit-mysql']['id']===$source['context']['initial_service_ids']['audit-mysql']
        &&$rows['audit-redis']['id']===$source['context']['initial_service_ids']['audit-redis'],
        'M4C_H5_BOOTSTRAP_PRECOLLECTED_TARGET_AND_DB_EXCLUDES_TRANSIENT_HELPER');
    m4ch_ms5_paid_projection($rows['audit-ms5-paid-producer'],m4ch_ms5_paid($source,$source['proof_name']));
    audit_register_resources();$manifest=audit_manifest();$actual=array_keys($manifest['services']);sort($actual,SORT_STRING);
    m4c_expect($actual===$wanted&&$manifest['services'][$targetRole]['id']===$target['id'],'M4C_H5_BOOTSTRAP_TARGET_THREE_ROLE_MANIFEST_READBACK');
    foreach($paths as$key=>$path)m4c_expect(file_get_contents($path)===$raws[$key],'M4C_H5_BOOTSTRAP_REGISTRATION_INPUTS_UNCHANGED');
    m4cb_guard_readonly();return ['target_proof'=>$targetProof,'target_role'=>$targetRole,'target_container_id'=>$target['id'],
        'registered_roles'=>$actual,'input_inventory_excludes_transient_helper'=>true];
}
/** Private bytes are kept only in memory; this function never returns them to a public result. */
function m4ch_bootstrap_sealed(string $path,int $maximum): array
{
    m4c_expect(in_array($path,['/run/audit-http-control/sql-baseline.json','/run/audit-http-control/prepare-result.json',
        '/run/audit-app/config.json','/run/audit-bootstrap/migrator.json'],true),'M4C_H5_BOOTSTRAP_FIXED_SEALED_INPUT');
    $parent=dirname($path);clearstatcache(true,$parent);clearstatcache(true,$path);$before=lstat($path);
    m4c_expect(is_dir($parent)&&!is_link($parent)&&realpath($parent)===$parent&&(fileperms($parent)&0777)===0700
        &&fileowner($parent)===posix_geteuid()&&is_array($before)&&($before['mode']&0170000)===0100000
        &&($before['mode']&0777)===0600&&$before['uid']===posix_geteuid()&&!is_link($path)&&realpath($path)===$path
        &&$before['size']>0&&$before['size']<=$maximum,'M4C_H5_BOOTSTRAP_SEALED_REGULAR_OWNER_MODE_SIZE');
    $raw=file_get_contents($path);clearstatcache(true,$path);$after=lstat($path);$keys=array_flip(['dev','ino','mode','uid','gid','size','mtime','ctime']);
    m4c_expect(is_string($raw)&&strlen($raw)===$before['size']&&is_array($after)
        &&array_intersect_key($before,$keys)===array_intersect_key($after,$keys),'M4C_H5_BOOTSTRAP_SEALED_FULL_READ_IDENTITY');
    $value=json_decode($raw,true,128,JSON_THROW_ON_ERROR);m4c_expect(is_array($value)&&!array_is_list($value),'M4C_H5_BOOTSTRAP_SEALED_JSON_OBJECT');
    return ['value'=>$value,'raw'=>$raw,'sha256'=>hash('sha256',$raw),'stat'=>array_intersect_key($before,$keys)];
}
/** Bound DML terminal/result, the original sealed base fixture and the complete sealed native B0. */
function m4ch_bootstrap_baseline(array $source): array
{
    $evidence='/run/audit/evidence/';$prepared=m4c_entry_terminal($source,M4CH_PROOF,M4CH_ROLE,[M4CH_ENTRY,'prepare']);
    $result=m4ch_entry_json($source['prepare_result'],'/run/audit-http-control/prepare-result.json');
    $sealedResult=m4ch_bootstrap_sealed('/run/audit-http-control/prepare-result.json',2097152);
    m4c_expect($sealedResult['value']===$result&&$sealedResult['sha256']===$source['prepare_result']['sha256'],
        'M4C_H5_BOOTSTRAP_ACTUAL_SEALED_DML_RESULT_BYTES');
    m4c_entry_keys($result,['schema','scope','status','run_id','source_sha','container_id','freeze_sha256','native_created_sha256',
        'source_binding_sha256','environment_sha256','public_copy_sha256','public_source_sha256','phase','native_exit',
        'full_source_unchanged','public_materials_unchanged','business','HTTP_executed','credential_login_authenticated',
        'order_creation_executed','HTTP_DOM','assertions','assertions_by_phase']);
    $dmlBinding=m4c_entry_read($evidence.'ms4-customer-h5-prepare-source-binding.json');
    $dmlEnvironment=m4c_entry_read($evidence.'customer-h5-prepare-environment.json');
    m4c_expect($result['schema']===1&&$result['scope']==='NORMAL_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
        &&$result['status']==='VERIFIED_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'&&$result['run_id']===$source['run_id']
        &&$result['source_sha']===$source['source_sha']&&$result['freeze_sha256']===$source['freeze_sha256']
        &&$result['container_id']===$prepared['id']&&$result['native_created_sha256']===hash_file('sha256',$evidence.M4CH_PROOF.'-created.json')
        &&$result['source_binding_sha256']===hash_file('sha256',$evidence.'ms4-customer-h5-prepare-source-binding.json')
        &&$result['environment_sha256']===hash_file('sha256',$evidence.'customer-h5-prepare-environment.json')
        &&$dmlBinding['run_id']===$source['run_id']&&$dmlBinding['source_sha']===$source['source_sha']
        &&$dmlBinding['container_id']===$prepared['id']&&$dmlBinding['freeze_sha256']===$source['freeze_sha256']
        &&$dmlBinding['source_files']===$source['source_files']&&$dmlBinding['h5_source_files']===$source['h5_source_files']
        &&$dmlBinding['h5_materials']===$source['h5_materials']&&$dmlBinding['public_copy']===$source['public_copy']
        &&m4ch_http_same_environment($dmlEnvironment['environment'],$source['environment'])&&$result['phase']==='final_source'&&$result['native_exit']===0
        &&$result['full_source_unchanged']===true&&$result['public_materials_unchanged']===true
        &&$result['HTTP_executed']===false&&$result['credential_login_authenticated']===false
        &&$result['order_creation_executed']===false&&$result['HTTP_DOM']==='NOT RUN'
        &&is_int($result['assertions'])&&$result['assertions']>0,'M4C_H5_BOOTSTRAP_ORIGINAL_NATIVE_DML_ZERO_AND_PUBLIC_BINDING');
    $h5=m4ch_entry_materials($source);
    m4c_expect($result['public_copy_sha256']===$h5['public_copy_sha256']&&$result['public_source_sha256']===$h5['public_source_sha256'],
        'M4C_H5_BOOTSTRAP_DML_SAME_ACTUAL_PUBLIC_COPY');
    $baseline=m4ch_bootstrap_sealed('/run/audit-http-control/sql-baseline.json',2097152);$value=$baseline['value'];
    m4c_entry_keys($value,['schema','run_id','source_sha','freeze_sha256','container_id','native_created_sha256','source_binding_sha256',
        'public_copy_sha256','public_source_sha256','runtime_origin','input_bindings','environment','self_operated_mer_id',
        'base_fixture_sha256','base_prepare_receipt_sha256','base_prepare_container_id','base_prepare_native_created_sha256',
        'base_prepare_source_binding_sha256','scope','native_snapshot']);
    $base=m4cb_fixture_load($source);$original=m4c_entry_terminal($source,'customer-prepare',M4C_MODE['prepare']['role'],[M4C_ENTRY,'prepare']);
    m4c_expect($value['schema']===1&&$value['scope']==='NORMAL_CUSTOMER_H5_SQL_BASELINE'
        &&$value['run_id']===$source['run_id']&&$value['source_sha']===$source['source_sha']&&$value['freeze_sha256']===$source['freeze_sha256']
        &&$value['container_id']===$prepared['id']&&$value['native_created_sha256']===$result['native_created_sha256']
        &&$value['source_binding_sha256']===$result['source_binding_sha256']&&$value['public_copy_sha256']===$h5['public_copy_sha256']
        &&$value['public_source_sha256']===$h5['public_source_sha256']&&$value['runtime_origin']===$h5['runtime_origin']
        &&$value['input_bindings']===$h5['inputs']&&m4ch_http_same_environment($value['environment'],$source['environment'])
        &&$value['self_operated_mer_id']===$source['self_operated_mer_id']&&$value['base_fixture_sha256']===$base['sha256']
        &&$value['base_prepare_receipt_sha256']===$base['prepare_receipt_sha256']&&$value['base_prepare_container_id']===$original['id']
        &&$value['base_prepare_container_id']===$base['private']['prepare_container_id']
        &&$value['base_prepare_native_created_sha256']===$base['private']['prepare_native_created_sha256']
        &&$value['base_prepare_source_binding_sha256']===$base['private']['prepare_source_binding_sha256'],
        'M4C_H5_BOOTSTRAP_SEALED_BASELINE_FULL_REAL_IDENTITY');
    $publicBaseline=$result['business']['sealed_files']['sql-baseline'];m4c_entry_keys($publicBaseline,['sha256_prefix','bytes']);
    m4c_expect($publicBaseline['sha256_prefix']===substr($baseline['sha256'],0,12)&&$publicBaseline['bytes']===strlen($baseline['raw'])
        &&$result['business']['fixture_counts']===['customers'=>1,'merchants'=>2,'products'=>3,'skus'=>4,'cart_rows'=>0,'orders'=>0]
        &&$result['business']['business_table_count']===27&&$result['business']['auxiliary_table_count']===7
        &&$result['business']['base_fixture_preserved']===true&&$result['business']['production_configuration_published_and_read_back']===true,
        'M4C_H5_BOOTSTRAP_DML_ACTUAL_BASELINE_BYTES_AND_PREPARATION_COUNTS');
    m4c_expect(is_array($value['native_snapshot']),'M4C_H5_BOOTSTRAP_EXACT_NATIVE_ARCHIVE_OBJECT');
    $snapshot=m4ch_native_snapshot_restore($value['native_snapshot']);
    m4c_expect(count(M4CB_TABLES)===27&&count(M4CH_AUX_TABLES)===7
        &&array_slice(M4CB_TABLES,25)===['eb_marketplace_payment_registry','eb_marketplace_payment_attempt']
        &&array_keys($snapshot['facts'])===M4CB_TABLES&&array_keys($snapshot['auxiliary_rows'])===M4CH_AUX_TABLES,
        'M4C_H5_BOOTSTRAP_COMPLETE_NATIVE_27_AND_7_BASELINE');
    unset($base['private']);$baseline['native_snapshot']=$snapshot;
    $baseline['base_fixture']=$base;$baseline['prepare_result']=$sealedResult;$baseline['h5']=$h5;
    return $baseline;
}
function m4ch_bootstrap_native_baseline(array $baseline): void
{
    $pdo=audit_db();m4c_expect(!$pdo->inTransaction()&&$pdo->query('SELECT CURRENT_USER()')->fetchColumn()==='audit_app@%',
        'M4C_H5_BOOTSTRAP_INDEPENDENT_APPLICATION_DML_READBACK');
    $facts=m4cb_snapshot($pdo);
    m4c_expect($facts===$baseline['native_snapshot']['facts'],'M4C_H5_BOOTSTRAP_NATIVE_ALL_27_ROWS_UNCHANGED');
    foreach(['eb_marketplace_payment_registry','eb_marketplace_payment_attempt']as$table)
        m4c_expect($facts[$table]===$baseline['native_snapshot']['facts'][$table],
            'M4C_H5_BOOTSTRAP_COMPLETE_ADDITIONAL_PAYMENT_TABLE_ROWS_UNCHANGED');
    foreach(M4CH_AUX_TABLES as$table)m4c_expect(m4cb_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY `id`')
        ===$baseline['native_snapshot']['auxiliary_rows'][$table],'M4C_H5_BOOTSTRAP_NATIVE_ALL_SEVEN_AUXILIARY_ROWS_UNCHANGED');
    m4cb_guard_readonly();
}
/** This separate files container owns its one real migrator App; it never switches an App to audit_app. */
function m4ch_bootstrap_migrator(array $source): array
{
    m4c_expect($source['mode']==='files-bootstrap'&&$source['proof_name']==='customer-h5-files'
        &&getenv('MS4_PROOF_NAME')==='customer-h5-files'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__,
        'M4C_H5_BOOTSTRAP_MIGRATOR_ONLY_IN_OWN_NATIVE_FILES_PROCESS');
    m4cb_guard_readonly();$privateApp=m4ch_bootstrap_sealed('/run/audit-app/config.json',1048576);
    $privateMigrator=m4ch_bootstrap_sealed('/run/audit-bootstrap/migrator.json',1048576);
    $config=$privateApp['value'];$migrator=$privateMigrator['value'];
    m4c_expect(($config['run_id']??null)===$source['run_id']&&($config['database']['username']??null)==='audit_app'
        &&($migrator['username']??null)==='audit_migrator'&&is_string($migrator['password']??null)&&$migrator['password']!==''
        &&($config['auth']['session_epoch']??null)==='audit-http-'.$source['run_id']
        &&is_string($config['signing_key']??null)&&strlen($config['signing_key'])>=32
        &&is_string($config['auth']['stamp_key']??null)&&strlen($config['auth']['stamp_key'])>=32
        &&!hash_equals($config['signing_key'],$config['auth']['stamp_key'])&&is_string($config['auth']['issuer']??null),
        'M4C_H5_BOOTSTRAP_REAL_DEDICATED_MIGRATOR_AND_PRIVATE_AUTH');
    m4c_expect(empty($GLOBALS['m4c_app_started'])&&empty($GLOBALS['m4ch_migrator_started']),'M4C_H5_BOOTSTRAP_ONLY_ONE_REAL_MIGRATOR_APP');
    $GLOBALS['m4ch_migrator_started']=true;require_once '/workspace/crmeb/vendor/autoload.php';$app=new \think\App('/workspace/crmeb/');
    $runtime='/tmp/ms4-customer-h5-migrator-'.$source['run_id'].'/';
    m4c_expect(!file_exists($runtime)&&mkdir($runtime,0700),'M4C_H5_BOOTSTRAP_MIGRATOR_DISTINCT_NATIVE_RUNTIME');$app->setRuntimePath($runtime);
    foreach($source['environment']as$key=>$value)m4c_expect(getenv($key)===$value,'M4C_H5_BOOTSTRAP_MIGRATOR_BOUND_REAL_PROCESS_ENVIRONMENT');
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'APP_ENV'=>'testing',
        'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306','DATABASE_DATABASE'=>'audit_auth',
        'DATABASE_USERNAME'=>'audit_migrator','DATABASE_PASSWORD'=>$migrator['password'],'DATABASE_PREFIX'=>'eb_',
        'DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'CACHE_DRIVER'=>'redis','REDIS_REDIS_HOSTNAME'=>'audit-redis',
        'REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,
        'CACHE_CACHE_PREFIX'=>'c:','CACHE_CACHE_TAG_PREFIX'=>'CRMEB:',
        'MARKETPLACE_ENABLED'=>true,'MARKETPLACE_TRADE_ENABLED'=>true,'MARKETPLACE_SELF_OPERATED_MER_ID'=>$source['self_operated_mer_id'],
        'AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer']]);
    $app->initialize();$connection=$app->db->connect();$identityRows=$connection->query('SELECT CURRENT_USER() AS actual_user',[],true);
    $native=$connection->getPdo();
    m4c_expect($native instanceof PDO&&!$native->inTransaction()&&is_array($identityRows)&&count($identityRows)===1
        &&($identityRows[0]['actual_user']??null)==='audit_migrator@%'
        &&$native->query('SELECT CURRENT_USER()')->fetchColumn()==='audit_migrator@%'
        &&$app->config->get('database.connections.mysql.username')==='audit_migrator'
        &&$connection===\think\facade\Db::connect()&&$native===\think\facade\Db::connect()->getPdo(),
        'M4C_H5_BOOTSTRAP_REAL_NATIVE_MIGRATOR_CONNECTION');
    $all=false;foreach($native->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)as$grant){
        if(preg_match('/^GRANT USAGE ON \*\.\* TO /',$grant)===1)continue;
        m4c_expect(preg_match('/^GRANT ALL PRIVILEGES ON `audit_auth`\.\* TO /',$grant)===1&&!str_contains($grant,'GRANT OPTION'),
            'M4C_H5_BOOTSTRAP_MIGRATOR_ONLY_DEDICATED_SCHEMA_GRANTS');$all=true;}
    m4c_expect($all&&$app->config->get('marketplace.enabled')===true&&$app->config->get('marketplace.trade.enabled')===true
        &&\app\services\marketplace\order\MarketplaceOrderInput::unsigned($app->config->get('marketplace.self_operated_mer_id'),'self_operated_mer_id')
            ===$source['self_operated_mer_id']&&$app->config->get('cache.default')==='redis'
        &&$app->config->get('cache.stores.redis.host')==='audit-redis'&&(string)$app->config->get('cache.stores.redis.port')==='6379'
        &&$app->config->get('cache.stores.redis.select')===0&&$app->config->get('cache.stores.redis.prefix')==='c:'
        &&$app->config->get('cache.stores.redis.tag_prefix')==='CRMEB:'&&$app->config->get('cache.stores.redis.password')===$config['redis_password']
        &&$app->env->get('app.app_key')===$config['signing_key']&&$app->config->get('auth.session_epoch')===$config['auth']['session_epoch']
        &&$app->config->get('auth.stamp_key')===$config['auth']['stamp_key']&&$app->config->get('auth.issuer')===$config['auth']['issuer'],
        'M4C_H5_BOOTSTRAP_REAL_CUSTOMER_CACHE_MARKETPLACE_AND_AUTH_CONFIGURATION');
    m4c_expect(empty($GLOBALS['m4ch_bootstrap_term']),'M4C_H5_BOOTSTRAP_NOT_INTERRUPTED_BEFORE_WRITE_MD5');
    m4c_expect($app->make(\app\services\system\log\SystemFileServices::class)->writeMd5()===true,'M4C_H5_BOOTSTRAP_REAL_FINAL_WRITE_MD5');
    $count=(int)audit_db()->query('SELECT COUNT(*) FROM eb_system_file_md5')->fetchColumn();
    m4c_expect($count>0&&$app->make(\app\services\system\log\SystemFileMd5Services::class)->checkFile()===[],
        'M4C_H5_BOOTSTRAP_REAL_FINAL_FILE_CHECK_MATCHES');
    $cache=$app->make(\app\services\other\CacheServices::class);$cache->setDbCache('write_md5',get_crmeb_version_vode());
    m4c_expect($cache->checkDbCache('write_md5',get_crmeb_version_vode()),'M4C_H5_BOOTSTRAP_REAL_DB_VERSION_CACHE_MARKER');
    m4c_expect(!$native->inTransaction()&&$native===\think\facade\Db::connect()->getPdo()
        &&$native->query('SELECT CURRENT_USER()')->fetchColumn()==='audit_migrator@%',
        'M4C_H5_BOOTSTRAP_ORIGINAL_MIGRATOR_APP_CONNECTION_PRESERVED');
    $appAfter=m4ch_bootstrap_sealed('/run/audit-app/config.json',1048576);
    $migratorAfter=m4ch_bootstrap_sealed('/run/audit-bootstrap/migrator.json',1048576);
    m4c_expect($privateApp['raw']===$appAfter['raw']&&$privateApp['stat']===$appAfter['stat']
        &&$privateMigrator['raw']===$migratorAfter['raw']&&$privateMigrator['stat']===$migratorAfter['stat'],
        'M4C_H5_BOOTSTRAP_PRIVATE_ACCOUNTS_UNCHANGED');
    m4cb_guard_readonly();m4c_expect(audit_db()->query('SELECT CURRENT_USER()')->fetchColumn()==='audit_app@%',
        'M4C_H5_BOOTSTRAP_APPLICATION_STILL_REAL_RESTRICTED_DML');
    unset($privateApp,$privateMigrator,$appAfter,$migratorAfter,$config,$migrator);
    return ['account'=>'audit_migrator','checksum_rows'=>$count,'source_matches'=>true,'version_marker_matches'=>true,
        'customer_cache_prefix'=>'c:','customer_cache_tag_prefix'=>'CRMEB:','application_account'=>'audit_app',
        'application_dml_only'=>true,'one_independent_migrator_App'=>true];
}
function m4ch_bootstrap_files_phase(array $source): array
{
    m4cb_phase('h5_files_before');$baseline=m4ch_bootstrap_baseline($source);m4ch_bootstrap_native_baseline($baseline);
    m4cb_phase('h5_files_migrator');$installation=m4ch_bootstrap_migrator($source);
    m4cb_phase('h5_files_after');m4ch_bootstrap_native_baseline($baseline);$after=m4ch_bootstrap_baseline($source);
    m4c_expect($after['raw']===$baseline['raw']&&$after['stat']===$baseline['stat']
        &&$after['prepare_result']['raw']===$baseline['prepare_result']['raw']&&$after['prepare_result']['stat']===$baseline['prepare_result']['stat']
        &&$after['base_fixture']===$baseline['base_fixture']&&$after['h5']===$baseline['h5'],
        'M4C_H5_BOOTSTRAP_ALL_SEALED_INPUTS_AND_PUBLIC_BINDINGS_UNCHANGED');
    unset($after,$baseline);return ['installation'=>$installation,'business_tables'=>27,'auxiliary_tables'=>7,
        'complete_native_rows_unchanged'=>true,'sealed_baseline_and_base_fixture_unchanged'=>true,
        'DML_prepare_actual_terminal_zero_verified'=>true,'public_materials_unchanged'=>true,
        'HTTP_executed'=>false,'credential_login_authenticated'=>false,'order_creation_executed'=>false,'HTTP_DOM'=>'NOT RUN'];
}
/** Final registration reads only these existing public inputs; it never mounts a private control volume. */
function m4ch_bootstrap_final_bytes(array $descriptor,string $path,int $maximum=16777216): string
{
    m4c_entry_keys($descriptor,['path','bytes','sha256']);
    m4c_expect($descriptor['path']===$path&&is_int($descriptor['bytes'])&&$descriptor['bytes']>0&&$descriptor['bytes']<=$maximum
        &&is_string($descriptor['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$descriptor['sha256'])===1
        &&is_file($path)&&!is_link($path)&&realpath($path)===$path,'M4C_H5_FINAL_FIXED_PUBLIC_DESCRIPTOR');
    $raw=file_get_contents($path);m4c_expect(is_string($raw)&&strlen($raw)===$descriptor['bytes']
        &&hash('sha256',$raw)===$descriptor['sha256'],'M4C_H5_FINAL_ACTUAL_BOUND_PUBLIC_BYTES');return $raw;
}
function m4ch_bootstrap_final_networks(array $source): array
{
    m4c_entry_keys($source['network_receipts'],['backend','frontend']);$result=[];
    foreach(['backend','frontend']as$kind){$path='/run/audit/evidence/http-'.$kind.'-network-created.json';
        $value=json_decode(m4ch_bootstrap_final_bytes($source['network_receipts'][$kind],$path),true,128,JSON_THROW_ON_ERROR);
        m4c_entry_keys($value,['schema','scope','run_id','source_sha','freeze_sha256','network']);
        m4c_expect($value['schema']===1&&$value['scope']==='MS4_HTTP_'.strtoupper($kind).'_NATIVE_IDENTITY_ONLY'
            &&$value['run_id']===$source['run_id']&&$value['source_sha']===$source['source_sha']
            &&$value['freeze_sha256']===$source['freeze_sha256'],'M4C_H5_FINAL_SAME_NETWORK_RECEIPT');$network=$value['network'];
        m4c_entry_keys($network,['Id','Name','Driver','Scope','Internal','EnableIPv6','Options','Labels','Containers']);
        m4c_expect(is_string($network['Id'])&&preg_match('/\A[a-f0-9]{64}\z/D',$network['Id'])===1
            &&$network['Driver']==='bridge'&&$network['Scope']==='local'&&$network['EnableIPv6']===false
            &&is_array($network['Containers']),'M4C_H5_FINAL_NATIVE_BRIDGE_IDENTITY');
        if($kind==='backend')m4c_expect(array_intersect_key($network,array_flip(['Id','Name','Internal','Labels']))
            ===$source['context']['initial_network']&&$network['Internal']===true,'M4C_H5_FINAL_ORIGINAL_BACKEND');
        else{$labels=['tianli.audit'=>'1','tianli.audit.run'=>$source['run_id'],
                'com.docker.compose.project'=>$source['context']['project'],'com.docker.compose.network'=>'frontend'];
            $options=['com.docker.network.enable_ipv4'=>'true','com.docker.network.bridge.enable_ip_masquerade'=>'false'];
            m4c_entry_keys($network['Labels'],array_keys($labels));m4c_entry_keys($network['Options'],array_keys($options));
            foreach($labels as$key=>$expected)m4c_expect($network['Labels'][$key]===$expected,'M4C_H5_FINAL_FRONTEND_LABEL_VALUE');
            foreach($options as$key=>$expected)m4c_expect($network['Options'][$key]===$expected,'M4C_H5_FINAL_FRONTEND_OPTION_VALUE');
            m4c_expect($network['Name']===$source['context']['project'].'_frontend'&&$network['Internal']===false
                &&$network['Containers']===[],'M4C_H5_FINAL_CREATED_NO_MASQUERADE_FRONTEND');}
        unset($network['Containers']);$result[$kind]=$network;
    }
    m4c_expect($result['backend']['Id']!==$result['frontend']['Id'],'M4C_H5_FINAL_DISTINCT_NATIVE_NETWORKS');return $result;
}
function m4ch_bootstrap_final_role(array $source,array $created,string $role,string $proof): void
{
    $ui=$role==='audit-ui';$helper=$proof==='register-customer-h5-serve';$project=$source['context']['project'];
    m4c_expect(in_array([$role,$proof],[['audit-ms4-customer-h5','customer-h5-serve'],['audit-ui','customer-h5-ui'],
        ['audit-ms4-customer-h5-register','register-customer-h5-serve']],true),'M4C_H5_FINAL_ONLY_THREE_FINITE_NEW_IDENTITIES');
    m4c_expect($created['run_id']===$source['run_id']&&$created['source_sha']===$source['source_sha']&&$created['proof_name']===$proof
        &&is_string($created['id'])&&preg_match('/\A[a-f0-9]{64}\z/D',$created['id'])===1
        &&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0&&$created['read_only']===true
        &&$created['privileged']===false&&$created['cap_drop']===['ALL']
        &&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true)
        &&$created['labels']['tianli.audit']==='1'&&$created['labels']['tianli.audit.run']===$source['run_id']
        &&$created['labels']['com.docker.compose.project']===$project&&$created['labels']['com.docker.compose.service']===$role,
        'M4C_H5_FINAL_REAL_CREATED_ROLE_BOUNDARY');
    $wanted=[];
    if($ui){$port=parse_url($source['h5_materials']['runtime_origin'],PHP_URL_PORT);
        $binding=['8080/tcp'=>[['HostIp'=>'127.0.0.1','HostPort'=>(string)$port]]];
        m4c_expect($created['image']==='sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236'
            &&$created['entrypoint']===['nginx']&&$created['command']===['-g','daemon off;','-c','/etc/nginx/nginx.conf']
            &&$created['user']==='101:101'&&$created['workdir']==='/'&&$created['port_bindings']===$binding
            &&in_array($created['network_mode'],[$project.'_isolation',$project.'_frontend'],true),
            'M4C_H5_FINAL_CREATED_NGINX_PROCESS_AND_LOOPBACK');
        $names=array_keys($created['networks']);sort($names,SORT_STRING);$expected=[$project.'_isolation',$project.'_frontend'];sort($expected,SORT_STRING);
        m4c_expect($names===$expected,'M4C_H5_FINAL_UI_ONLY_TWO_NETWORKS');
        foreach($created['ports']??[]as$name=>$value)m4c_expect(in_array($name,['80/tcp','8080/tcp'],true)
            &&($value===null||$name==='8080/tcp'&&$value===$binding['8080/tcp']),'M4C_H5_FINAL_UI_ONLY_DECLARED_PORT');
        $wanted=['/srv/public'=>['volume',$project.'_http-public',false],
            '/etc/nginx/nginx.conf'=>['bind',$source['context']['prep_dir'].'/ms4-h5-nginx.conf',false]];
    }else{
        m4c_expect($created['image']===M4C_IMAGE&&$created['entrypoint']===['php']&&$created['workdir']==='/workspace'
            &&in_array($created['user'],['','0','0:0','root'],true)
            &&$created['command']===($helper?[M4CH_BOOTSTRAP,'register-final']:[M4CH_ENTRY,'serve'])
            &&$created['network_mode']===$project.'_isolation'&&array_keys($created['networks'])===[$project.'_isolation']
            &&in_array($created['port_bindings'],[null,[]],true),'M4C_H5_FINAL_CREATED_PHP_COMMAND_AND_BACKEND');
        foreach($created['ports']??[]as$value)m4c_expect(in_array($value,[null,[]],true),'M4C_H5_FINAL_PHP_NO_HOST_PORT');
        m4c_expect(!in_array('audit-app',$created['networks'][$project.'_isolation']['Aliases']??[],true),
            'M4C_H5_FINAL_NO_CUSTOM_APPLICATION_ALIAS');
        foreach(['/workspace'=>$source['context']['repo_dir'],'/source'=>$source['context']['repo_dir'],
            '/run/ms4-prep'=>$source['context']['prep_dir'],'/run/audit'=>$source['context']['run_dir']]as$path=>$host)
            $wanted[$path]=['bind',$host,$helper&&$path==='/run/audit'];
        $wanted['/run/audit-app']=['volume',$project.'_app-secrets',false];
        if(!$helper){m4c_expect($created['stop_signal']==='SIGTERM','M4C_H5_FINAL_ACTUAL_SERVE_SIGTERM');
            $wanted+=['/run/audit-fixtures'=>['volume',$project.'_fixture-secrets',false],
                '/workspace/crmeb/public'=>['volume',$project.'_http-public',false],
                '/workspace/crmeb/runtime'=>['volume',$project.'_http-runtime',true],
                '/run/audit-http-control'=>['volume',$project.'_http-control',true],
                '/run/audit-http-browser'=>['volume',$project.'_http-browser-secrets',false]];}
    }
    m4c_expect(array_keys($created['tmpfs'])===['/tmp']&&is_string($created['tmpfs']['/tmp'])
        &&in_array('rw',explode(',',$created['tmpfs']['/tmp']),true),'M4C_H5_FINAL_ONLY_RW_TMPFS');$seen=[];
    foreach($created['mounts']as$mount){if($mount['Type']==='tmpfs'){
            m4c_expect($mount['Destination']==='/tmp'&&$mount['RW']===true,'M4C_H5_FINAL_ONLY_TMP_MOUNT');continue;}
        $path=$mount['Destination'];m4c_expect(isset($wanted[$path])&&!isset($seen[$path]),'M4C_H5_FINAL_CLOSED_MOUNT_DESTINATIONS');
        [$type,$origin,$rw]=$wanted[$path];$seen[$path]=true;
        m4c_expect($mount['Type']===$type&&$mount['RW']===$rw&&($type==='volume'?$mount['Name']===$origin:
            audit_canonical_host_bind_source($mount['Source'])===audit_canonical_host_bind_source($origin)),
            'M4C_H5_FINAL_EXACT_NATIVE_MOUNT_IDENTITY_ACCESS');}
    m4c_expect(count($seen)===count($wanted),'M4C_H5_FINAL_COMPLETE_CLOSED_MOUNT_SET');
}
function m4ch_bootstrap_final_prerequisites(array $source): void
{
    $e='/run/audit/evidence/';$terminals=[];
    foreach([['initializer','audit-ms4-initialize',['/run/ms4-prep/ms4-initialize.php']],
        ['driver','audit-ms5-payment-migration',['help/audit-regressions/isolated/ms5-payment-migration.php']],
        ['files-bootstrap','audit-bootstrap',['/run/ms4-prep/ms4-initialize.php','files-bootstrap']],
        ['customer-prepare','audit-ms4-customer-prepare',[M4C_ENTRY,'prepare']],
        ['customer-h5-public-copy','audit-ms4-customer-h5-public-copy',['/run/ms4-prep/ms4-h5-public-copy.php','copy']],
        ['customer-h5-prepare',M4CH_ROLE,[M4CH_ENTRY,'prepare']],
        ['customer-h5-files','audit-ms4-customer-h5-files',[M4CH_BOOTSTRAP,'files-bootstrap']]]as[$proof,$role,$command])
        $terminals[$proof]=m4c_entry_terminal($source,$proof,$role,$command);
    m4c_entry_ms5_history($source);
    m4c_entry_early_history($source);
    m4ch_ms5_prerequisites($source,'register-final');
    $public=m4ch_entry_json($source['public_copy'],$e.'customer-h5-public-copy.json');
    m4c_entry_keys($public,['schema','scope','run_id','source_sha','freeze_sha256','source_identity','read_only_source',
        'source_count','h5_count','public_count','files','directories','inputs_unchanged','closed_set_verified','marker_sha256','png_sha256','permissions']);
    m4c_expect($public['schema']===1&&$public['scope']==='MS4_CURRENT_H5_PUBLIC_SNAPSHOT_ONLY'&&$public['run_id']===$source['run_id']
        &&$public['source_sha']===$source['source_sha']&&$public['freeze_sha256']===$source['freeze_sha256']
        &&$public['source_identity']===['proof_name'=>'customer-h5-public-copy','role'=>'audit-ms4-customer-h5-public-copy',
            'command'=>['/run/ms4-prep/ms4-h5-public-copy.php','copy'],'container_id'=>$terminals['customer-h5-public-copy']['id'],
            'native_created_sha256'=>hash_file('sha256',$e.'customer-h5-public-copy-created.json')]
        &&$public['read_only_source']===true&&$public['inputs_unchanged']===true&&$public['closed_set_verified']===true,
        'M4C_H5_FINAL_BOUND_ACTUAL_PUBLIC_COPIER');
    foreach(['prepare','files']as$name){$proof='customer-h5-'.$name;$path=$e.$proof.'-result.json';
        $value=m4ch_entry_json($source[$name.'_result'],$path);$copy=m4c_entry_read($e.$proof.'-result-copy.json');
        m4c_expect($copy['schema']===1&&$copy['scope']==='MS4_CUSTOMER_H5_FIXED_PUBLIC_RESULT_EXTRACTION_ONLY'
            &&$copy['run_id']===$source['run_id']&&$copy['source_sha']===$source['source_sha']&&$copy['freeze_sha256']===$source['freeze_sha256']
            &&$copy['container_id']===$terminals[$proof]['id']&&$copy['container_path']==='/run/audit-http-control/'.$name.'-result.json'
            &&$copy['output_name']===$proof.'-result.json'&&$copy['native_exit']===0&&$copy['stderr_bytes']===0
            &&$copy['stderr_sha256']===hash('sha256','')&&$copy['bytes']===$source[$name.'_result']['bytes']
            &&$copy['sha256']===$source[$name.'_result']['sha256']
            &&$copy['native_created_sha256']===hash_file('sha256',$e.$proof.'-created.json')
            &&$copy['native_terminal_sha256']===hash_file('sha256',$e.$proof.'-terminal.json'),
            'M4C_H5_FINAL_ACTUAL_SAME_CID_RESULT_EXTRACTION');
        m4c_expect($value['schema']===1&&$value['run_id']===$source['run_id']&&$value['source_sha']===$source['source_sha']
            &&$value['container_id']===$terminals[$proof]['id']&&$value['freeze_sha256']===$source['freeze_sha256']
            &&$value['native_exit']===0&&$value['full_source_unchanged']===true&&$value['HTTP_DOM']==='NOT RUN'
            &&$value['native_created_sha256']===hash_file('sha256',$e.$proof.'-created.json')
            &&$value['source_binding_sha256']===hash_file('sha256',$e.($name==='prepare'?'ms4-customer-h5-prepare':$proof).'-source-binding.json'),
            'M4C_H5_FINAL_ACTUAL_PREPARE_FILES_SOURCE_RESULT');
        $binding=m4c_entry_read($e.($name==='prepare'?'ms4-customer-h5-prepare':$proof).'-source-binding.json');
        m4c_expect($binding['source_files']===$source['source_files']&&$binding['h5_source_files']===$source['h5_source_files']
            &&$binding['freeze_sha256']===$source['freeze_sha256'],'M4C_H5_FINAL_PREPARE_FILES_SAME_SOURCE');
        if($name==='prepare')m4c_expect($value['scope']==='NORMAL_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
            &&$value['status']==='VERIFIED_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'&&$value['phase']==='final_source'
            &&$value['public_materials_unchanged']===true&&$value['public_copy_sha256']===$source['public_copy']['sha256']
            &&$value['public_source_sha256']===$source['h5_materials']['public_source']['sha256']
            &&$value['business']['fixture_counts']===['customers'=>1,'merchants'=>2,'products'=>3,'skus'=>4,'cart_rows'=>0,'orders'=>0]
            &&$value['business']['business_table_count']===27&&$value['business']['auxiliary_table_count']===7,
            'M4C_H5_FINAL_REAL_H5_PREPARATION_COUNTS');
        else m4c_expect($value['scope']==='CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'&&$value['status']==='VERIFIED_CUSTOMER_H5_WITH_MS5_FILES_BOOTSTRAP_ONLY'
            &&$value['phase']==='files-bootstrap'&&$value['business']['business_tables']===27&&$value['business']['auxiliary_tables']===7
            &&$value['business']['complete_native_rows_unchanged']===true&&$value['business']['sealed_baseline_and_base_fixture_unchanged']===true
            &&$value['business']['DML_prepare_actual_terminal_zero_verified']===true&&$value['business']['public_materials_unchanged']===true,
            'M4C_H5_FINAL_REAL_FINAL_FILES_RESULT');
    }
}
function m4ch_bootstrap_final_source(): array
{
    $phase='register-final';$proof='register-customer-h5-serve';$e='/run/audit/evidence/';$run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===M4CH_BOOTSTRAP&&($_SERVER['argv']??[])===[M4CH_BOOTSTRAP,$phase]
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit'
        &&getenv('MS4_PROOF_NAME')===$proof&&getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'
        &&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run)===1&&preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha)===1,
        'M4C_H5_FINAL_REAL_NATIVE_REGISTER_ENTRY');
    $context=m4c_entry_read('/run/audit/actual-run-context.json');$freeze=m4c_entry_read('/run/audit/preparation-source-binding.json');
    $bindingPath=$e.$proof.'-source-binding.json';$binding=m4c_entry_read($bindingPath);$created=m4c_entry_read($e.$proof.'-created.json');
    m4c_entry_keys($binding,['schema','scope','phase','proof_name','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','h5_source_files','artifact_files',
        'h5_artifact_files','environment','public_copy','prepare_result','files_result','registration_inputs','network_receipts','stop_signal','targets','prerequisites_sha256','canonical_paid']);
    $extra=M4C_EXTRA;sort($extra,SORT_STRING);$freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    m4c_expect($context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$context['freeze_sha256']===$freezeHash
        &&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'&&$freeze['source_sha']===$sha
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$extra
        &&$binding['schema']===1&&$binding['scope']==='MS4_CUSTOMER_H5_FINAL_REGISTRATION_MS5_SOURCE'
        &&$binding['phase']===$phase&&$binding['proof_name']===$proof&&$binding['run_id']===$run&&$binding['source_sha']===$sha
        &&$binding['container_id']===$created['id']&&$binding['native_created_sha256']===hash_file('sha256',$e.$proof.'-created.json')
        &&$binding['freeze_sha256']===$freezeHash&&$binding['requested_repo_source']===$context['repo_dir']
        &&$binding['requested_run_source']===$context['run_dir']&&$binding['requested_prep_source']===$context['prep_dir']
        &&$binding['entry_sha256']===hash_file('sha256',M4CH_BOOTSTRAP)
        &&$binding['source_files']===$freeze['customer_source_files']&&$binding['h5_source_files']===$freeze['h5_source_files']
        &&$binding['artifact_files']===$freeze['artifact_files']&&$binding['h5_artifact_files']===$freeze['h5_artifact_files'],
        'M4C_H5_FINAL_COMPLETE_ORIGINAL_SOURCE_AND_NATIVE_BINDING');
    $source=$binding+['mode'=>$phase,'context'=>$context,'freeze'=>$freeze,'h5_materials'=>$freeze['h5_materials'],
        'source_binding_sha256'=>hash_file('sha256',$bindingPath),'self_operated_mer_id'=>(int)$binding['environment']['PHP_MARKETPLACE_SELF_OPERATED_MER_ID']];
    m4c_expect(gethostname()===substr($created['id'],0,12)&&$created['hostname']===gethostname(),'M4C_H5_FINAL_ACTUAL_HELPER_HOSTNAME');
    m4ch_bootstrap_final_role($source,$created,'audit-ms4-customer-h5-register',$proof);
    $values=$binding['environment'];m4c_entry_keys($values,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED',
        'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER']);
    m4c_expect($values['APP_ENV']==='testing'&&$values['PHP_MARKETPLACE_ENABLED']==='true'&&$values['PHP_MARKETPLACE_TRADE_ENABLED']==='true'
        &&$values['PHP_MARKETPLACE_PAYMENT_TEST_ENABLED']==='true'&&$values['PHP_CACHE_DRIVER']==='redis'&&is_string($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])
        &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])===1
        &&bccomp($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'4294967295',0)<=0,'M4C_H5_FINAL_EXACT_ENABLED_CUSTOMER_ENVIRONMENT');
    foreach($values as$key=>$value)m4c_expect(getenv($key)===$value,'M4C_H5_FINAL_ACTUAL_NATIVE_ENVIRONMENT_VALUE');
    m4c_entry_native_environment($values,m4c_entry_read($e.$proof.'-environment-native.json'));
    m4c_expect(!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')&&!file_exists('/source/crmeb/public/install.lock'),
        'M4C_H5_FINAL_NO_HOST_APPLICATION_CONFIGURATION');m4c_entry_full_source($source);
    $h5=m4ch_entry_rows($source['h5_source_files']);m4c_expect(array_column($h5,'path')===M4CH_SOURCE_FILES,'M4C_H5_FINAL_EXACT_TWO_H5_SOURCES');
    foreach(['/source','/workspace']as$root){m4ch_bootstrap_files($root,$h5);m4ch_bootstrap_files($root,$freeze['driver_source_files']);
        m4ch_bootstrap_files($root,$freeze['preparation_source_references']);}
    m4c_expect(array_column($source['artifact_files'],'path')===M4CH_ORIGINAL_ARTIFACTS,'M4C_H5_FINAL_ORIGINAL_SEVEN_ARTIFACTS');
    $paths=['H5-README.md','compose.h5.yml','h5-derivation.json','ms4-h5-host.py','ms4-h5-materials.py','ms4-h5-bootstrap.php',
        'ms4-h5-public-copy.php','ms4-h5-nginx.conf','ms4-order.png','inputs/language.json','inputs/theme.json','manifests/public-source.json',
        'manifests/h5-dist.json','manifests/inputs.json','h5-materials-result.json','browser-v1/customer-network-observer.mjs',
        'browser-v1/ms4-customer-private-bridge.mjs','browser-v1/ms4-customer-browser.mjs','normal-close-customer-h5.py','verify-zero-customer-h5.py'];
    m4c_expect(array_column($source['h5_artifact_files'],'path')===$paths,'M4C_H5_FINAL_ALL_TWENTY_REAL_ARTIFACTS_REQUIRED');
    m4ch_bootstrap_files('/run/ms4-prep',$source['artifact_files']);m4ch_bootstrap_files('/run/ms4-prep',$source['h5_artifact_files']);
    $networks=m4ch_bootstrap_final_networks($source);$backend=$networks['backend'];
    m4c_expect(in_array($created['networks'][$backend['Name']]['NetworkID'],['',$backend['Id']],true),'M4C_H5_FINAL_HELPER_REAL_BACKEND_ID');
    m4c_entry_keys($binding['targets'],['serve','ui']);$ids=[$created['id']];
    foreach(['serve'=>['audit-ms4-customer-h5','customer-h5-serve'],'ui'=>['audit-ui','customer-h5-ui']]as$name=>[$role,$targetProof]){
        $target=m4ch_entry_json($binding['targets'][$name],$e.$targetProof.'-created.json');
        m4ch_bootstrap_final_role($source,$target,$role,$targetProof);m4c_expect(!in_array($target['id'],$ids,true),'M4C_H5_FINAL_DISTINCT_TARGET_CIDS');$ids[]=$target['id'];}
    $stop=m4ch_entry_json($binding['stop_signal'],$e.'http-app-stop-signal.json');
    m4c_entry_keys($stop,['schema','scope','run_id','source_sha','freeze_sha256','container_id','native_created_sha256','effective_stop_signal','native_inspect']);
    m4c_expect($stop['schema']===1&&$stop['scope']==='MS4_HTTP_APP_STOP_SIGNAL_NATIVE_ONLY'&&$stop['run_id']===$run
        &&$stop['source_sha']===$sha&&$stop['freeze_sha256']===$freezeHash&&$stop['container_id']===$ids[1]
        &&$stop['native_created_sha256']===$binding['targets']['serve']['sha256']&&$stop['effective_stop_signal']==='SIGTERM'
        &&$stop['native_inspect']['exit_code']===0&&$stop['native_inspect']['stderr_bytes']===0
        &&$stop['native_inspect']['stderr_sha256']===hash('sha256',''),'M4C_H5_FINAL_REAL_APP_STOP_SIGNAL_RECEIPT');
    $source['copy_profile']=m4ch_bootstrap_materials($source);m4ch_bootstrap_final_prerequisites($source);m4cb_guard_readonly();return $source;
}
function m4ch_bootstrap_register_final(array $source): array
{
    $paths=['containers'=>'/run/audit/containers.jsonl','volumes'=>'/run/audit/volumes.txt','volume_identities'=>'/run/audit/volumes.jsonl'];
    m4c_entry_keys($source['registration_inputs'],array_keys($paths));$raws=[];
    foreach($paths as$key=>$path)$raws[$key]=m4ch_bootstrap_final_bytes($source['registration_inputs'][$key],$path);
    m4c_expect(is_file('/run/audit/manifest.json')&&!is_link('/run/audit/manifest.json')
        &&realpath('/run/audit/manifest.json')==='/run/audit/manifest.json','M4C_H5_FINAL_EXISTING_REGULAR_MANIFEST');
    $rows=[];$seenIds=[];$network=$source['context']['initial_network'];$project=$source['context']['project'];$manifest=audit_manifest();
    m4c_expect($manifest['run_id']===$source['run_id']&&$manifest['commit_sha']===$source['source_sha']&&$manifest['project']===$project
        &&$manifest['isolation']==='ISO-FULL'&&$manifest['guard_mode']==='read-only'&&$manifest['network_internal']===true,
        'M4C_H5_FINAL_SAME_EXISTING_MANIFEST');$wanted=['audit-ms4-customer-h5','audit-ms5-paid-producer','audit-mysql','audit-redis','audit-ui'];sort($wanted,SORT_STRING);
    $targets=['audit-ms4-customer-h5'=>m4c_entry_read('/run/audit/evidence/customer-h5-serve-created.json'),
        'audit-ui'=>m4c_entry_read('/run/audit/evidence/customer-h5-ui-created.json'),
        'audit-ms5-paid-producer'=>m4ch_ms5_paid($source,$source['proof_name'])];
    $mounts=static function(array$values):array{$result=[];foreach($values as$value){ksort($value,SORT_STRING);$result[]=json_encode($value,JSON_THROW_ON_ERROR);}
        sort($result,SORT_STRING);return $result;};
    foreach(explode("\n",trim($raws['containers']))as$line){$row=json_decode($line,true,128,JSON_THROW_ON_ERROR);
        m4c_entry_keys($row,['id','image_id','mounts','labels','networks','ports','read_only','privileged','network_mode']);
        $role=$row['labels']['com.docker.compose.service'];m4c_expect(in_array($role,$wanted,true)&&!isset($rows[$role])&&!isset($seenIds[$row['id']])
            &&$row['labels']['tianli.audit']==='1'&&$row['labels']['tianli.audit.run']===$source['run_id']
            &&$row['labels']['com.docker.compose.project']===$project&&$row['id']!==$source['container_id'],
            'M4C_H5_FINAL_FOUR_UNIQUE_TARGET_INPUTS_EXCLUDE_HELPER');
        $original=$targets[$role]??$source['context']['initial_service_created'][$role];
        if($role==='audit-ms5-paid-producer')m4ch_ms5_paid_projection($row,$original);
        m4c_expect($row['id']===$original['id']&&$row['image_id']===$original['image']&&$row['labels']===$original['labels']
            &&$row['read_only']===$original['read_only']&&$row['privileged']===$original['privileged']
            &&$row['network_mode']===$original['network_mode']&&$mounts($row['mounts'])===$mounts($original['mounts']),
            'M4C_H5_FINAL_ORIGINAL_NATIVE_TARGET_PROJECTION');
        if(isset($targets[$role]))m4c_expect($row['networks']===$original['networks']&&$row['ports']===$original['ports'],
            'M4C_H5_FINAL_UNSTARTED_TARGET_NETWORK_PROJECTION');
        else{m4c_expect($row['id']===$source['context']['initial_service_ids'][$role]&&array_keys($row['networks'])===[$network['Name']]
                &&$row['networks'][$network['Name']]['NetworkID']===$network['Id']
                &&filter_var($row['networks'][$network['Name']]['IPAddress'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false
                &&$row['networks'][$network['Name']]['IPAddress']===$manifest['services'][$role]['address'],
                'M4C_H5_FINAL_ORIGINAL_DB_CID_AND_ADDRESS_STRICT');
            foreach($row['ports']??[]as$value)m4c_expect(in_array($value,[null,[]],true),'M4C_H5_FINAL_DB_NO_HOST_PORT');}
        $rows[$role]=$row;$seenIds[$row['id']]=true;
    }
    $roles=array_keys($rows);sort($roles,SORT_STRING);m4c_expect($roles===$wanted,'M4C_H5_FINAL_EXACT_FOUR_MANIFEST_TARGETS');
    $names=explode("\n",trim($raws['volumes']));$volumeRows=[];$all=[];
    foreach(explode("\n",trim($raws['volume_identities']))as$line){$value=json_decode($line,true,128,JSON_THROW_ON_ERROR);
        m4c_entry_keys($value,['name','created_at','labels']);m4c_expect(is_string($value['name'])&&!isset($volumeRows[$value['name']])
            &&is_string($value['created_at'])&&$value['created_at']!==''&&$value['labels']['tianli.audit']==='1'
            &&$value['labels']['tianli.audit.run']===$source['run_id']&&$value['labels']['com.docker.compose.project']===$project,
            'M4C_H5_FINAL_ACTUAL_VOLUME_IDENTITY_ROW');$volumeRows[$value['name']]=$value;}
    $added=m4c_entry_read('/run/audit/evidence/customer-h5-additional-volume-identities.json');
    m4c_expect($added['schema']===1&&$added['scope']==='MS4_CUSTOMER_H5_ADDITIONAL_NATIVE_VOLUMES_ONLY'
        &&$added['run_id']===$source['run_id']&&$added['source_sha']===$source['source_sha']&&$added['freeze_sha256']===$source['freeze_sha256']
        &&$added['original_context_sha256']===hash_file('sha256','/run/audit/actual-run-context.json')
        &&$added['network_id']===$source['context']['network_id']&&count($added['additional_volumes'])===4,
        'M4C_H5_FINAL_ORIGINAL_CONTEXT_AND_FOUR_ADDITIONAL_IDENTITIES');
    foreach([...$source['context']['initial_volumes'],...$added['additional_volumes']]as$value){
        m4c_expect(isset($volumeRows[$value['name']])&&$volumeRows[$value['name']]===$value&&!isset($all[$value['name']]),
            'M4C_H5_FINAL_SAME_ORIGINAL_SIX_PLUS_FOUR_VOLUMES');$all[$value['name']]=$value['created_at'];}
    ksort($all,SORT_STRING);m4c_expect(count($all)===count($source['context']['initial_volumes'])+count($added['additional_volumes'])&&count($volumeRows)===count($all)&&$names===array_keys($all)
        &&$added['all_volume_names']===array_keys($all),'M4C_H5_FINAL_EXACT_TEN_VOLUME_INPUTS');
    $services=[];foreach($rows as$role=>$row)$services[$role]=['id'=>$row['id'],
        'address'=>$row['networks'][$network['Name']]['IPAddress'],'image_id'=>$row['image_id']];
    $before='/run/audit/evidence/http-manifest-before-0001.json';m4c_expect(!file_exists($before)&&!is_link($before),'M4C_H5_FINAL_MANIFEST_ARCHIVE_CREATE_ONCE');
    m4c_entry_publish($before,$manifest);$manifest['services']=$services;$manifest['network_internal']=true;
    $manifest['network']=['id'=>$network['Id'],'name'=>$network['Name']];$manifest['frontend_network']=m4ch_bootstrap_final_networks($source)['frontend'];
    $manifest['http_stop_signal_sha256']=$source['stop_signal']['sha256'];$manifest['volumes']=$names;$manifest['volume_identities']=$all;
    $raw=m4cb_json($manifest);$written=file_put_contents('/run/audit/manifest.json',$raw,LOCK_EX);
    m4c_expect($written===strlen($raw)&&file_get_contents('/run/audit/manifest.json')===$raw&&audit_manifest()===$manifest,
        'M4C_H5_FINAL_FOUR_ROLE_MANIFEST_FULL_READBACK');
    foreach($paths as$key=>$path)m4c_expect(file_get_contents($path)===$raws[$key],'M4C_H5_FINAL_ORIGINAL_PRESTART_INPUT_BYTES_UNCHANGED');
    m4cb_guard_readonly();return ['registered_roles'=>$roles,'input_inventory_excludes_transient_helper'=>true,
        'manifest_sha256'=>hash_file('sha256','/run/audit/manifest.json')];
}
function m4ch_bootstrap_main(): int
{
    $phase=$GLOBALS['m4ch_bootstrap_phase']=$GLOBALS['argv'][1]??'';
    m4c_expect(in_array($phase,['register','files-bootstrap','register-final'],true),'M4C_H5_BOOTSTRAP_MAIN_ONLY_REGISTER_OR_FILES');
    m4cb_phase('source');$source=m4ch_bootstrap_source($phase);$GLOBALS['m4ch_bootstrap_source']=$source;
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4ch_bootstrap_term']=true;});
    $business=match($phase){'register'=>m4ch_bootstrap_register($source),'register-final'=>m4ch_bootstrap_register_final($source),
        'files-bootstrap'=>m4ch_bootstrap_files_phase($source)};
    m4cb_phase('final_source');$after=m4ch_bootstrap_source($phase);
    m4c_expect($after===$source,'M4C_H5_BOOTSTRAP_ALL_FROZEN_SOURCE_BINDINGS_UNCHANGED');
    $status=match($phase){'register'=>'REGISTERED_CUSTOMER_H5_WITH_MS5_TARGET_RESOURCES_ONLY','register-final'=>'REGISTERED_CUSTOMER_H5_WITH_MS5_FINAL_RESOURCES_ONLY',
        'files-bootstrap'=>'VERIFIED_CUSTOMER_H5_WITH_MS5_FILES_BOOTSTRAP_ONLY'};
    $result=['schema'=>1,'scope'=>'CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY','status'=>$status,'phase'=>$phase,
        'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],
        'freeze_sha256'=>$source['freeze_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'proof_name'=>$source['proof_name'],
        'full_source_unchanged'=>true,'native_exit'=>0,'business'=>$business,'HTTP_DOM'=>'NOT RUN'];
    m4c_expect(empty($GLOBALS['m4ch_bootstrap_term']),'M4C_H5_BOOTSTRAP_NOT_INTERRUPTED_BEFORE_RESULT');
    $target=in_array($phase,['register','register-final'],true)?'/run/audit/evidence/'.$source['proof_name'].'-result.json':'/run/audit-http-control/files-result.json';
    m4c_entry_publish($target,$result);
    m4c_expect(empty($GLOBALS['m4ch_bootstrap_term']),'M4C_H5_BOOTSTRAP_NOT_INTERRUPTED_AFTER_RESULT');
    echo json_encode(['status'=>$status,'phase'=>$phase,'native_exit'=>0,'HTTP_DOM'=>'NOT RUN'],JSON_THROW_ON_ERROR)."\n";return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    set_error_handler(static function(int$severity,string$message,string$file,int$line):bool{
        if((error_reporting()&$severity)===0)return false;
        $GLOBALS['m4c_warning']=['severity'=>$severity,'file'=>basename($file),'line'=>$line];
        $GLOBALS['m4c_failed_assertion']='M4C_PHP_WARNING';throw new RuntimeException('M4C_PHP_WARNING');});
    try{exit(m4ch_bootstrap_main());}
    catch(Throwable$error){$source=$GLOBALS['m4ch_bootstrap_source']??null;$code=$GLOBALS['m4c_failed_assertion']??'';
        $safe=['schema'=>1,'status'=>'FAIL_PRESERVED','scope'=>'CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY',
            'phase'=>$GLOBALS['m4c_phase']??'source','class'=>get_class($error),
            'error_location'=>['file'=>basename($error->getFile()),'line'=>$error->getLine()],
            'code'=>is_string($code)&&preg_match('/\AM4C_[A-Z0-9_]+\z/D',$code)===1?$code:'M4C_UNCLASSIFIED',
            'native_exit'=>1,'HTTP_DOM'=>'NOT RUN'];
        if(is_array($source)){foreach(['run_id','source_sha','container_id','freeze_sha256']as$key)$safe[$key]=$source[$key];
            try{$target=in_array($source['mode'],['register','register-final'],true)?'/run/audit/evidence/'.$source['proof_name'].'-result.json':'/run/audit-http-control/files-result.json';
                m4c_entry_publish($target,$safe);}catch(Throwable$ignored){}}
        echo json_encode($safe,JSON_THROW_ON_ERROR)."\n";exit(1);}
}
