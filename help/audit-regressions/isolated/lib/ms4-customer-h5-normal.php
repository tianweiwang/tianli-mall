<?php
declare(strict_types=1);

/** Real H5 preparation only. The entry owns source/resource gates and the final public receipt. */
require_once __DIR__.'/ms4-customer-http-normal.php';

use app\services\diy\ThemeServices;
use app\services\marketplace\PublicCatalogServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\publiccatalog\PublicCatalogProjection;
use app\services\system\config\SystemConfigServices;
use app\services\system\lang\LangCodeServices;
use app\services\system\lang\LangCountryServices;
use app\services\system\lang\LangTypeServices;
use crmeb\services\CacheService;
use crmeb\services\SystemConfigService;
use think\db\ConnectionInterface;
use think\facade\Db;

const M4CH_AUX_TABLES=['eb_theme','eb_lang_type','eb_lang_country','eb_lang_code',
    'eb_store_visit','eb_user_visit','eb_message_system'];

/** MS5 H5 public prerequisites only. Copy's strict history is mandatory in its publisher after copying. */
function m4ch_ms5_prerequisite_names(string $actualPhase): array
{
    $counts=['copy'=>16,'register-prepare'=>19,'prepare'=>19,'register-files'=>22,
        'files-bootstrap'=>22,'register-final'=>25,'serve'=>28];
    m4c_expect(isset($counts[$actualPhase]),'M4C_H5_MS5_ACTUAL_PHASE');
    $names=[...M4C_COMMON_PREREQUISITES,...M4C_FILES_PREREQUISITES,...M4C_PREPARE_PREREQUISITES,
        'customer-h5-additional-volume-identities.json'];
    if($actualPhase!=='copy')$names=[...$names,'customer-h5-public-copy-created.json',
        'customer-h5-public-copy-terminal.json','customer-h5-public-copy.json'];
    if(in_array($actualPhase,['register-files','files-bootstrap','register-final','serve'],true))
        $names=[...$names,'customer-h5-prepare-created.json','customer-h5-prepare-terminal.json','customer-h5-prepare-result.json'];
    if(in_array($actualPhase,['register-final','serve'],true))
        $names=[...$names,'customer-h5-files-created.json','customer-h5-files-terminal.json','customer-h5-files-result.json'];
    if($actualPhase==='serve')$names=[...$names,'register-customer-h5-serve-created.json',
        'register-customer-h5-serve-terminal.json','register-customer-h5-serve-result.json'];
    m4c_expect(count($names)===$counts[$actualPhase]&&count(array_unique($names,SORT_STRING))===count($names),
        'M4C_H5_MS5_EXACT_PHASE_FILE_CLOSURE');
    return $names;
}
/** A real wire descriptor can refer to a private fixed path; compare its bytes with the actual public extraction. */
function m4ch_ms5_public_descriptor(array $descriptor,string $wirePath,string $publicPath): void
{
    m4c_entry_keys($descriptor,['path','bytes','sha256']);clearstatcache(true,$publicPath);
    m4c_expect($descriptor['path']===$wirePath&&is_int($descriptor['bytes'])&&$descriptor['bytes']>0
        &&$descriptor['bytes']<=2097152&&is_string($descriptor['sha256'])
        &&preg_match('/\A[a-f0-9]{64}\z/D',$descriptor['sha256'])===1
        &&is_file($publicPath)&&!is_link($publicPath)&&realpath($publicPath)===$publicPath
        &&filesize($publicPath)===$descriptor['bytes']&&m4c_entry_hash($publicPath)===$descriptor['sha256'],
        'M4C_H5_MS5_REAL_PUBLIC_DESCRIPTOR_BYTES');
}
/** Verify completed H5 predecessor bindings without requiring their removed containers or private mounts. */
function m4ch_ms5_prior_binding(array $source,string $kind): array
{
    $rules=[
        'copy'=>['customer-h5-public-copy','audit-ms4-customer-h5-public-copy',
            ['/run/ms4-prep/ms4-h5-public-copy.php','copy'],'copy','ms4-h5-public-copy.php'],
        'prepare'=>['customer-h5-prepare','audit-ms4-customer-h5-prepare',[M4CH_ENTRY,'prepare'],'prepare',M4CH_ENTRY],
        'files-bootstrap'=>['customer-h5-files','audit-ms4-customer-h5-files',
            ['/run/ms4-prep/ms4-h5-bootstrap.php','files-bootstrap'],'files-bootstrap','ms4-h5-bootstrap.php'],
        'register-final'=>['register-customer-h5-serve','audit-ms4-customer-h5-register',
            ['/run/ms4-prep/ms4-h5-bootstrap.php','register-final'],'register-final','ms4-h5-bootstrap.php']];
    m4c_expect(isset($rules[$kind]),'M4C_H5_MS5_FINITE_PRIOR_BINDING');
    [$proof,$role,$command,$phase,$entry]=$rules[$kind];$e='/run/audit/evidence/';
    $created=m4c_entry_terminal($source,$proof,$role,$command);
    $name=$kind==='prepare'?'ms4-customer-h5-prepare-source-binding.json':$proof.'-source-binding.json';
    $binding=m4c_entry_read($e.$name);
    $common=['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','h5_source_files'];
    if($kind==='prepare'){$keys=[...$common,'h5_materials','public_copy','prerequisites_sha256'];
        $scope='MS4_CUSTOMER_H5_PREPARE_MS5_SOURCE';$rows=$source['freeze']['h5_source_files'];}
    elseif($kind==='register-final'){$keys=[...$common,'phase','proof_name','artifact_files','h5_artifact_files','environment',
        'public_copy','prepare_result','files_result','registration_inputs','network_receipts','stop_signal','targets','prerequisites_sha256'];
        $scope='MS4_CUSTOMER_H5_FINAL_REGISTRATION_MS5_SOURCE';$rows=$source['freeze']['h5_artifact_files'];}
    else{$keys=[...$common,'phase','proof_name','artifact_files','h5_artifact_files','environment','public_copy',
        'prepare_result','registration_inputs','prerequisites_sha256'];
        $scope='MS4_CUSTOMER_H5_BOOTSTRAP_MS5_SOURCE';$rows=$source['freeze']['h5_artifact_files'];}
    m4c_entry_keys($binding,$keys);
    $entries=array_values(array_filter($rows,static fn(array $row):bool=>$row['path']===$entry));
    m4c_expect(count($entries)===1&&$binding['schema']===1&&$binding['scope']===$scope
        &&$binding['run_id']===$source['run_id']&&$binding['source_sha']===$source['source_sha']
        &&$binding['container_id']===$created['id']&&$binding['native_created_sha256']===m4c_entry_hash($e.$proof.'-created.json')
        &&$binding['freeze_sha256']===$source['freeze_sha256']
        &&$binding['requested_repo_source']===$source['context']['repo_dir']
        &&$binding['requested_run_source']===$source['context']['run_dir']
        &&$binding['requested_prep_source']===$source['context']['prep_dir']
        &&$binding['entry_sha256']===$entries[0]['sha256'],'M4C_H5_MS5_PRIOR_ACTUAL_ENTRY_AND_NATIVE_BINDING');
    foreach(['source_files'=>'customer_source_files','h5_source_files'=>'h5_source_files']as$key=>$frozen)
        m4c_entry_same($binding[$key],$source['freeze'][$frozen]);
    if($kind==='prepare')m4c_entry_same($binding['h5_materials'],$source['freeze']['h5_materials']);
    else{m4c_expect($binding['phase']===$phase&&$binding['proof_name']===$proof,'M4C_H5_MS5_PRIOR_REAL_PHASE');
        foreach(['artifact_files','h5_artifact_files']as$key)m4c_entry_same($binding[$key],$source['freeze'][$key]);}
    $names=m4ch_ms5_prerequisite_names($kind);m4c_entry_keys($binding['prerequisites_sha256'],$names);
    foreach($names as$file)m4c_expect($binding['prerequisites_sha256'][$file]===$source['prerequisites_sha256'][$file]
        &&$binding['prerequisites_sha256'][$file]===m4c_entry_hash($e.$file),'M4C_H5_MS5_PRIOR_COMPLETE_PREFIX_HASHES');
    if($kind==='prepare')$values=m4c_entry_environment($binding,$source['context'],$proof,'MS4_CUSTOMER_H5_MS5_REAL_ENVIRONMENT');
    else $values=m4c_entry_native_environment($binding['environment'],m4c_entry_read($e.$proof.'-environment-native.json'));
    m4c_entry_same($values,$source['environment']);
    return ['created'=>$created,'binding'=>$binding,'binding_name'=>$name];
}
/** The copied public receipt is checked against full frozen manifests, not counts or a private tree. */
function m4ch_ms5_public_copy(array $source,array $prior): void
{
    $e='/run/audit/evidence/';$created=$prior['created'];$binding=$prior['binding'];
    m4c_expect($binding['public_copy']===null&&$binding['prepare_result']===null&&$binding['registration_inputs']===null,
        'M4C_H5_MS5_COPY_HAS_NO_FUTURE_RECEIPTS');
    $materials=$source['freeze']['h5_materials'];
    $public=m4ch_entry_json($materials['public_source'],M4CH_MANIFESTS['public_source']);
    $dist=m4ch_entry_json($materials['dist'],M4CH_MANIFESTS['dist']);
    foreach([$public,$dist]as$manifest){m4c_entry_keys($manifest,['schema','files','directories']);
        m4c_expect($manifest['schema']===1,'M4C_H5_MS5_FROZEN_PUBLIC_MANIFEST_SCHEMA');
        m4ch_entry_rows($manifest['files']);m4ch_entry_directories($manifest['directories']);}
    $expected=[];$directories=[];
    foreach([...array_column($public['files'],'path'),...$public['directories']]as$path)
        m4c_expect($path!=='install.lock'&&!str_starts_with($path,'__ms4_assets')&&!str_starts_with($path,'__ms4_fixture__'),
            'M4C_H5_MS5_PUBLIC_RESERVED_NAMES_ABSENT');
    foreach($public['files']as$row)$expected[$row['path']]=$row;
    foreach($public['directories']as$directory)$directories[$directory]=true;
    m4c_expect(in_array('index.html',array_column($dist['files'],'path'),true),'M4C_H5_MS5_REAL_DIST_INDEX');
    foreach($dist['files']as$row){$path='__ms4_assets/h5/'.$row['path'];
        m4c_expect(!isset($expected[$path]),'M4C_H5_MS5_NO_DIST_COLLISION');
        $expected[$path]=['path'=>$path,'bytes'=>$row['bytes'],'sha256'=>$row['sha256']];}
    foreach($dist['directories']as$directory)$directories['__ms4_assets/h5/'.$directory]=true;
    $png=$materials['fixture_png'];m4c_entry_keys($png,['path','bytes','sha256']);
    m4c_expect($png['path']==='/run/ms4-prep/ms4-order.png'&&is_int($png['bytes'])&&$png['bytes']>0&&$png['bytes']<=2097152
        &&is_file($png['path'])&&!is_link($png['path'])&&realpath($png['path'])===$png['path']
        &&filesize($png['path'])===$png['bytes']&&m4c_entry_hash($png['path'])===$png['sha256'],'M4C_H5_MS5_FROZEN_PNG_BYTES');
    $marker='# AUDIT-RUN '.$source['run_id']."\n";
    $expected['install.lock']=['path'=>'install.lock','bytes'=>strlen($marker),'sha256'=>hash('sha256',$marker)];
    $expected['__ms4_fixture__/product.png']=['path'=>'__ms4_fixture__/product.png','bytes'=>$png['bytes'],'sha256'=>$png['sha256']];
    foreach(array_keys($expected)as$path)for($parent=dirname($path);$parent!=='.';$parent=dirname($parent))$directories[$parent]=true;
    ksort($expected,SORT_STRING);$directories=array_keys($directories);sort($directories,SORT_STRING);
    $copy=m4c_entry_read($e.'customer-h5-public-copy.json');
    m4c_entry_keys($copy,['schema','scope','run_id','source_sha','freeze_sha256','source_identity','read_only_source',
        'source_count','h5_count','public_count','files','directories','inputs_unchanged','closed_set_verified','marker_sha256','png_sha256','permissions']);
    m4c_entry_same($copy['source_identity'],['proof_name'=>'customer-h5-public-copy','role'=>'audit-ms4-customer-h5-public-copy',
        'command'=>['/run/ms4-prep/ms4-h5-public-copy.php','copy'],'container_id'=>$created['id'],
        'native_created_sha256'=>m4c_entry_hash($e.'customer-h5-public-copy-created.json')]);
    m4c_expect($copy['schema']===1&&$copy['scope']==='MS4_CURRENT_H5_PUBLIC_SNAPSHOT_ONLY'
        &&$copy['run_id']===$source['run_id']&&$copy['source_sha']===$source['source_sha']&&$copy['freeze_sha256']===$source['freeze_sha256']
        &&$copy['read_only_source']===true&&$copy['inputs_unchanged']===true&&$copy['closed_set_verified']===true
        &&$copy['source_count']===count($public['files'])&&$copy['h5_count']===count($dist['files'])
        &&$copy['public_count']===count($expected)&&$copy['files']===array_values($expected)&&$copy['directories']===$directories
        &&$copy['marker_sha256']===hash('sha256',$marker)&&$copy['png_sha256']===$png['sha256'],'M4C_H5_MS5_COMPLETE_PUBLIC_COPY_ORIGINAL');
    m4c_entry_keys($copy['permissions'],['publisher_uid','directory_mode','file_mode','verified']);
    m4c_expect(is_int($copy['permissions']['publisher_uid'])&&$copy['permissions']['publisher_uid']>=0
        &&$copy['permissions']['directory_mode']==='0755'&&$copy['permissions']['file_mode']==='0644'
        &&$copy['permissions']['verified']===true,'M4C_H5_MS5_PUBLIC_COPY_ACTUAL_PERMISSIONS');
    foreach(['/source'=>[$source['context']['repo_dir'],false],'/workspace/crmeb/public'=>['http-public',true]]as$target=>$wanted){
        $mounts=array_values(array_filter($created['mounts'],static fn(array $row):bool=>$row['Destination']===$target));
        m4c_expect(count($mounts)===1&&$mounts[0]['RW']===$wanted[1],'M4C_H5_MS5_PUBLIC_COPY_NATIVE_ACCESS');
        m4c_expect($target==='/source'?($mounts[0]['Type']==='bind'
            &&audit_canonical_host_bind_source($mounts[0]['Source'])===audit_canonical_host_bind_source($wanted[0]))
            :($mounts[0]['Type']==='volume'&&$mounts[0]['Name']===$source['context']['project'].'_'.$wanted[0]),
            'M4C_H5_MS5_PUBLIC_COPY_REAL_SOURCE_AND_VOLUME');}
}
/** Public extraction proves the complete original private result bytes; no private file is opened here. */
function m4ch_ms5_extracted_result(array $source,string $proof,array $prior): array
{
    $e='/run/audit/evidence/';$file=$proof.'-result.json';$path=$e.$file;
    $result=m4c_entry_read($path,2097152);$copy=m4c_entry_read($e.$proof.'-result-copy.json');$created=$prior['created'];
    m4c_entry_keys($copy,['schema','scope','run_id','source_sha','freeze_sha256','container_id','container_path','output_name',
        'native_created_sha256','native_terminal_sha256','native_exit','stderr_bytes','stderr_sha256','tar_bytes','tar_sha256','bytes','sha256']);
    $private=$proof==='customer-h5-prepare'?'prepare-result.json':'files-result.json';
    m4c_expect($copy['schema']===1&&$copy['scope']==='MS4_CUSTOMER_H5_FIXED_PUBLIC_RESULT_EXTRACTION_ONLY'
        &&$copy['run_id']===$source['run_id']&&$copy['source_sha']===$source['source_sha']&&$copy['freeze_sha256']===$source['freeze_sha256']
        &&$copy['container_id']===$created['id']&&$copy['container_path']==='/run/audit-http-control/'.$private&&$copy['output_name']===$file
        &&$copy['native_created_sha256']===m4c_entry_hash($e.$proof.'-created.json')
        &&$copy['native_terminal_sha256']===m4c_entry_hash($e.$proof.'-terminal.json')
        &&$copy['native_exit']===0&&$copy['stderr_bytes']===0&&$copy['stderr_sha256']===hash('sha256','')
        &&is_int($copy['tar_bytes'])&&$copy['tar_bytes']>0&&$copy['tar_bytes']<=4194304
        &&is_string($copy['tar_sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$copy['tar_sha256'])===1
        &&is_int($copy['bytes'])&&$copy['bytes']>0&&$copy['bytes']===filesize($path)&&$copy['sha256']===m4c_entry_hash($path),
        'M4C_H5_MS5_REAL_COMPLETE_SAME_CID_PUBLIC_EXTRACTION');
    m4c_expect($result['schema']===1&&$result['run_id']===$source['run_id']&&$result['source_sha']===$source['source_sha']
        &&$result['container_id']===$created['id']&&$result['freeze_sha256']===$source['freeze_sha256']
        &&$result['native_created_sha256']===m4c_entry_hash($e.$proof.'-created.json')
        &&$result['source_binding_sha256']===m4c_entry_hash($e.$prior['binding_name'])
        &&$result['phase']===($proof==='customer-h5-prepare'?'final_source':'files-bootstrap')
        &&$result['native_exit']===0&&$result['full_source_unchanged']===true,
        'M4C_H5_MS5_REAL_EXTRACTED_RESULT_HEADER');
    return $result;
}
function m4ch_ms5_prerequisites(array $source,string $actualPhase): void
{
    $e='/run/audit/evidence/';$names=m4ch_ms5_prerequisite_names($actualPhase);$hashes=$source['prerequisites_sha256'];
    m4c_entry_keys($hashes,$names);
    foreach($names as$name)m4c_expect(is_string($hashes[$name])&&preg_match('/\A[a-f0-9]{64}\z/D',$hashes[$name])===1
        &&$hashes[$name]===m4c_entry_hash($e.$name),'M4C_H5_MS5_COMPLETE_PHASE_ORIGINAL_HASH');
    $rules=['copy'=>['copy','customer-h5-public-copy','audit-ms4-customer-h5-public-copy'],
        'register-prepare'=>['register','register-customer-h5-prepare','audit-ms4-customer-h5-register'],
        'prepare'=>['prepare','customer-h5-prepare','audit-ms4-customer-h5-prepare'],
        'register-files'=>['register','register-customer-h5-files','audit-ms4-customer-h5-register'],
        'files-bootstrap'=>['files-bootstrap','customer-h5-files','audit-ms4-customer-h5-files'],
        'register-final'=>['register-final','register-customer-h5-serve','audit-ms4-customer-h5-register'],
        'serve'=>['serve','customer-h5-serve','audit-ms4-customer-h5']];
    [$mode,$proof,$role]=$rules[$actualPhase];$createdPath=$e.$proof.'-created.json';
    $own=m4c_entry_read($createdPath);$bindingName=$actualPhase==='prepare'?'ms4-customer-h5-prepare-source-binding.json'
        :($actualPhase==='serve'?'ms4-customer-h5-serve-source-binding.json':$proof.'-source-binding.json');
    m4c_expect($source['mode']===$mode&&$source['container_id']===$own['id']
        &&$source['native_created_sha256']===m4c_entry_hash($createdPath)
        &&$source['source_binding_sha256']===m4c_entry_hash($e.$bindingName)
        &&$own['run_id']===$source['run_id']&&$own['source_sha']===$source['source_sha']&&$own['proof_name']===$proof
        &&$own['labels']['com.docker.compose.service']===$role,'M4C_H5_MS5_CALLER_REAL_PHASE_AND_BINDING');
    if(!in_array($actualPhase,['prepare','serve'],true))m4c_expect($source['proof_name']===$proof,'M4C_H5_MS5_CALLER_ORIGINAL_PROOF');
    $contextPath='/run/audit/actual-run-context.json';$freezePath='/run/audit/preparation-source-binding.json';
    $contextHash=m4c_entry_hash($contextPath);$context=m4c_entry_read($contextPath);$freeze=m4c_entry_read($freezePath);
    m4c_expect($context===$source['context']&&$freeze===$source['freeze']
        &&$context['run_id']===$source['run_id']&&$context['source_sha']===$source['source_sha']
        &&$context['project']==='tianli-audit-'.$source['run_id']&&$context['freeze_sha256']===$source['freeze_sha256']
        &&m4c_entry_hash($freezePath)===$source['freeze_sha256']&&$freeze['source_sha']===$source['source_sha']
        &&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$context['repo_dir']===$source['requested_repo_source']&&$context['run_dir']===$source['requested_run_source']
        &&$context['prep_dir']===$source['requested_prep_source'],'M4C_H5_MS5_REAL_ORIGINAL_CONTEXT_FREEZE');
    $network=$context['initial_network'];m4c_entry_keys($network,['Id','Name','Internal','Labels']);
    m4c_expect($network['Id']===$context['network_id']&&$network['Name']===$context['project'].'_isolation'&&$network['Internal']===true
        &&$network['Labels']['tianli.audit']==='1'&&$network['Labels']['tianli.audit.run']===$source['run_id']
        &&$network['Labels']['com.docker.compose.project']===$context['project'],'M4C_H5_MS5_ORIGINAL_BACKEND_NETWORK');
    m4c_entry_keys($context['initial_service_ids'],['audit-mysql','audit-redis']);
    m4c_entry_keys($context['initial_service_created'],['audit-mysql','audit-redis']);
    m4c_expect(count(array_unique(array_values($context['initial_service_ids']),SORT_STRING))===2,
        'M4C_H5_MS5_DISTINCT_ORIGINAL_DB_CIDS');
    $volumes=[];$wanted=[];foreach(['app-secrets','bootstrap-secrets','fixture-secrets','mysql-data','redis-data','redis-secrets']as$name)
        $wanted[]=$context['project'].'_'.$name;sort($wanted,SORT_STRING);
    m4c_expect(is_array($context['initial_volumes'])&&array_is_list($context['initial_volumes'])&&count($context['initial_volumes'])===6,
        'M4C_H5_MS5_ORIGINAL_SIX_COMPLETE_ROWS');
    foreach($context['initial_volumes']as$row){m4c_entry_keys($row,['name','created_at','labels']);
        m4c_expect(is_string($row['name'])&&!isset($volumes[$row['name']])&&is_string($row['created_at'])&&$row['created_at']!==''
            &&$row['labels']['tianli.audit']==='1'&&$row['labels']['tianli.audit.run']===$source['run_id']
            &&$row['labels']['com.docker.compose.project']===$context['project'],'M4C_H5_MS5_ORIGINAL_FULL_VOLUME_IDENTITY');
        $volumes[$row['name']]=$row['created_at'];}
    ksort($volumes,SORT_STRING);m4c_expect(array_keys($volumes)===$wanted,'M4C_H5_MS5_ORIGINAL_EXACT_SIX_VOLUME_NAMES');
    $added=m4c_entry_read($e.'customer-h5-additional-volume-identities.json');
    m4c_entry_keys($added,['schema','scope','run_id','source_sha','freeze_sha256','original_context_sha256','network_id','additional_volumes','all_volume_names']);
    m4c_expect($added['schema']===1&&$added['scope']==='MS4_CUSTOMER_H5_ADDITIONAL_NATIVE_VOLUMES_ONLY'
        &&$added['run_id']===$source['run_id']&&$added['source_sha']===$source['source_sha']&&$added['freeze_sha256']===$source['freeze_sha256']
        &&$added['original_context_sha256']===$contextHash&&$added['network_id']===$network['Id']
        &&is_array($added['additional_volumes'])&&array_is_list($added['additional_volumes'])&&count($added['additional_volumes'])===4,
        'M4C_H5_MS5_A1_REAL_CONTEXT_AND_FOUR_VOLUMES');
    $additional=[];foreach($added['additional_volumes']as$row){m4c_entry_keys($row,['name','created_at','labels']);
        m4c_expect(is_string($row['name'])&&!isset($volumes[$row['name']])&&!isset($additional[$row['name']])
            &&is_string($row['created_at'])&&$row['created_at']!==''&&$row['labels']['tianli.audit']==='1'
            &&$row['labels']['tianli.audit.run']===$source['run_id']&&$row['labels']['com.docker.compose.project']===$context['project'],
            'M4C_H5_MS5_A1_COMPLETE_ADDITIONAL_VOLUME_ROW');$additional[$row['name']]=$row['created_at'];}
    ksort($additional,SORT_STRING);$wantedAdditional=[];foreach(['http-browser-secrets','http-control','http-public','http-runtime']as$name)
        $wantedAdditional[]=$context['project'].'_'.$name;
    m4c_expect(array_keys($additional)===$wantedAdditional,'M4C_H5_MS5_A1_EXACT_ADDITIONAL_NAMES');
    $allVolumes=$volumes+$additional;ksort($allVolumes,SORT_STRING);
    m4c_expect($added['all_volume_names']===array_keys($allVolumes),'M4C_H5_MS5_A1_EXACT_TEN_NAMES');
    $historyNames=array_merge(M4C_COMMON_PREREQUISITES,M4C_FILES_PREREQUISITES);
    $historyHashes=array_intersect_key($hashes,array_flip($historyNames));$ids=[$source['container_id']];
    if($actualPhase==='copy'){
        // Only this actual copy phase defers the strict PHP 9+4/20-source history to its publisher.
        // Host already verifies full history before copying; do not manufacture a history return or receipt here.
        $initCreated=m4c_entry_predecessor($source,'initializer','audit-ms4-initialize',['/run/ms4-prep/ms4-initialize.php'],
            'initializer-created.json','initializer-completed-terminal.json');
        $migrationCreated=m4c_entry_predecessor($source,'driver','audit-ms5-payment-migration',
            ['help/audit-regressions/isolated/ms5-payment-migration.php'],'ms5-payment-migration-container-created.json','driver-terminal.json');
        $filesCreated=m4c_entry_terminal($source,'files-bootstrap','audit-bootstrap',['/run/ms4-prep/ms4-initialize.php','files-bootstrap']);
        $init=m4c_entry_read($e.'initializer-result.json');$migration=m4c_entry_read($e.'ms5-payment-migration-normal.json');
        $binding=m4c_entry_read($e.'ms5-payment-migration-source-binding.json');
        m4c_entry_keys($binding,['schema','run_id','source_sha','requested_repo_source','requested_run_source','entry_sha256','cli_sha256','source_files']);
        m4c_expect($binding['schema']===1&&$binding['run_id']===$source['run_id']&&$binding['source_sha']===$source['source_sha']
            &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
            &&$binding['entry_sha256']===m4c_entry_hash('/source/help/audit-regressions/isolated/ms5-payment-migration.php')
            &&$binding['cli_sha256']===m4c_entry_hash('/source/help/migrations/marketplace-ms5-payment.php'),'M4C_H5_MS5_COPY_ORIGINAL_MIGRATION_BINDING');
        m4c_entry_same($binding['source_files'],$freeze['driver_source_files']);$sourcePaths=[];
        foreach($binding['source_files']as$row){m4c_entry_keys($row,['path','bytes','sha256']);$sourcePaths[]=$row['path'];
            foreach(['/source','/workspace']as$root){if($root==='/workspace'&&$row['path']==='crmeb/public/install/crmeb.sql')continue;
                $path=$root.'/'.$row['path'];m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
                    &&filesize($path)===$row['bytes']&&m4c_entry_hash($path)===$row['sha256'],'M4C_H5_MS5_COPY_ORIGINAL_SOURCE_WITH_ONE_PRECOPY_SQL_BOUNDARY');}}
        $expectedPaths=M4C_MIGRATION_SOURCES;sort($sourcePaths,SORT_STRING);sort($expectedPaths,SORT_STRING);
        m4c_expect($sourcePaths===$expectedPaths,'M4C_H5_MS5_COPY_TEN_UNREDUCED_SOURCE_DESCRIPTORS');
        m4c_expect($init['schema']===1&&$init['scope']==='MS5_PAYMENT_PREREQUISITES_ONLY'
            &&$init['status']==='VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY'&&$init['native_exit']===0&&$init['private_cleanup_verified']===true
            &&$init['run_id']===$source['run_id']&&$init['source_sha']===$source['source_sha']
            &&$init['binding']['container_id']===$initCreated['id']&&$init['binding']['source_sha']===$source['source_sha']
            &&$init['binding']['freeze_sha256']===$source['freeze_sha256']
            &&$init['binding']['created_sha256']===$hashes['initializer-created.json']
            &&is_array($init['children'])&&array_is_list($init['children'])&&count($init['children'])===9
            &&is_int($init['actual_self_mer_id'])&&$init['actual_self_mer_id']===$source['self_operated_mer_id'],
            'M4C_H5_MS5_COPY_ORIGINAL_INITIALIZER_IDENTITY_ONLY');
        m4c_expect($migration['schema']===1&&$migration['scope']==='MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'
            &&$migration['status']==='VERIFIED_MS5_PAYMENT_NORMAL_STRUCTURE_ONLY'&&array_key_exists('native_exit',$migration)
            &&$migration['native_exit']===null&&$migration['native_exit_observation']==='HOST_NATIVE_REQUIRED'
            &&$migration['entry_return_code']===0&&$migration['termination']==='NORMAL_RETURN'
            &&$migration['private_cleanup_verified']===true&&$migration['private_cleanup_state']==='OWN_FILE_REMOVED'
            &&$migration['binding']['run_id']===$source['run_id']&&$migration['binding']['source_sha']===$source['source_sha']
            &&$migration['binding']['container_id']===$migrationCreated['id']
            &&$migration['binding']['native_created_sha256']===$hashes['ms5-payment-migration-container-created.json']
            &&$migration['binding']['binding_sha256']===$hashes['ms5-payment-migration-source-binding.json']
            &&$migration['binding']['entry_sha256']===$binding['entry_sha256']&&$migration['binding']['cli_sha256']===$binding['cli_sha256']
            &&$migration['binding']['source_files']===10&&$migration['actual_command_counts']===['check'=>2,'apply-structure'=>2]
            &&is_array($migration['children'])&&array_is_list($migration['children'])&&count($migration['children'])===4,
            'M4C_H5_MS5_COPY_REAL_MIGRATION_IDENTITY_AND_SEPARATE_NATIVE_ZERO');
        $files=m4c_entry_read($e.'files-bootstrap-result.json');$shutdown=m4c_entry_read($e.'files-bootstrap-shutdown.json');
        m4c_expect($files['schema']===1&&$files['scope']==='MS5_PAYMENT_PREREQUISITES_ONLY'
            &&$files['status']==='VERIFIED_CUSTOMER_FILES_BOOTSTRAP_ONLY'&&$files['native_exit']===0
            &&$files['run_id']===$source['run_id']&&$files['source_sha']===$source['source_sha']
            &&$files['binding']['container_id']===$filesCreated['id']&&$files['binding']['source_sha']===$source['source_sha']
            &&$files['binding']['freeze_sha256']===$source['freeze_sha256']&&$files['binding']['created_sha256']===$hashes['files-bootstrap-created.json']
            &&$files['actual_self_mer_id']===$source['self_operated_mer_id']
            &&$files['initializer_result_sha256']===$hashes['initializer-result.json']&&$files['migration_result_sha256']===$hashes['ms5-payment-migration-normal.json']
            &&$files['installation']['account']==='audit_migrator'&&is_int($files['installation']['checksum_rows'])&&$files['installation']['checksum_rows']>0
            &&$files['installation']['source_matches']===true&&$files['installation']['version_marker_matches']===true
            &&$files['installation']['application_account']==='audit_app'&&$files['installation']['application_dml_only']===true
            &&$shutdown['phase']==='files-bootstrap'&&$shutdown['fatal']===false&&$shutdown['primary_failure']===null&&$shutdown['cleanup_failure']===null,
            'M4C_H5_MS5_COPY_REAL_FILES_PREDECESSOR');
        $formal=m4c_entry_read($e.'ms5-payment-migration-formal-manifest.json');
        $ids=[...$ids,$initCreated['id'],$migrationCreated['id'],$filesCreated['id']];
        m4c_entry_keys($formal['services'],['audit-ms5-payment-migration','audit-mysql','audit-redis']);
        m4c_expect($formal['services']['audit-ms5-payment-migration']['id']===$migrationCreated['id']
            &&$formal['services']['audit-ms5-payment-migration']['image_id']===M4C_IMAGE,'M4C_H5_MS5_COPY_ORIGINAL_MIGRATION_FORMAL');
    }else{
        $history=m4c_entry_ms5_history($source);
        m4c_entry_keys($history,['self_operated_mer_id','initializer_id','migration_id','files_id','formal','context_sha256','history_sha256']);
        m4c_expect($history['self_operated_mer_id']===$source['self_operated_mer_id']&&$history['context_sha256']===$contextHash,
            'M4C_H5_MS5_STRICT_SHARED_HISTORY_SELF_AND_CONTEXT');
        m4c_entry_same($history['history_sha256'],$historyHashes);$formal=$history['formal'];
        $ids=[...$ids,$history['initializer_id'],$history['migration_id'],$history['files_id']];
    }
    m4c_expect(count($ids)===count(array_unique($ids,SORT_STRING)),'M4C_H5_MS5_CURRENT_AND_HISTORICAL_CIDS_DISTINCT');
    m4c_expect($formal['run_id']===$source['run_id']&&$formal['commit_sha']===$source['source_sha']
        &&$formal['project']===$context['project']&&$formal['guard_mode']==='read-only'&&$formal['isolation']==='ISO-FULL'
        &&$formal['network_internal']===true,'M4C_H5_MS5_ORIGINAL_FORMAL_ISOLATION');
    m4c_entry_same($formal['network'],['id'=>$network['Id'],'name'=>$network['Name']]);
    $formalVolumes=$formal['volumes'];sort($formalVolumes,SORT_STRING);
    m4c_expect($formalVolumes===array_keys($volumes),'M4C_H5_MS5_ORIGINAL_FORMAL_SIX_VOLUMES');m4c_entry_same($formal['volume_identities'],$volumes);
    $manifest=audit_manifest();
    m4c_expect($manifest['run_id']===$source['run_id']&&$manifest['commit_sha']===$source['source_sha']
        &&$manifest['project']===$context['project']&&$manifest['isolation']==='ISO-FULL'
        &&$manifest['guard_mode']==='read-only'&&$manifest['network_internal']===true,'M4C_H5_MS5_ACTUAL_CURRENT_MANIFEST');
    m4c_entry_same($manifest['network'],['id'=>$network['Id'],'name'=>$network['Name']]);
    $dbImages=['audit-mysql'=>'sha256:c7a313f1402365dc91253bdbdb1e74c6a361b036106d8bd25b8ec8e2dd0069f4',
        'audit-redis'=>'sha256:6c8f6edc1aa871a76a257ef86a2089d108e64b78d16f45b89f09c6a4ab30d07e'];
    foreach($dbImages as$dbRole=>$image){$facts=$context['initial_service_created'][$dbRole];
        m4c_expect($facts['id']===$context['initial_service_ids'][$dbRole]&&is_string($facts['id'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$facts['id'])===1&&$facts['image']===$image
            &&$facts['status']==='created'&&$facts['pid']===0&&$facts['restart_count']===0
            &&$facts['labels']['tianli.audit']==='1'&&$facts['labels']['tianli.audit.run']===$source['run_id']
            &&$facts['labels']['com.docker.compose.project']===$context['project']&&$facts['labels']['com.docker.compose.service']===$dbRole
            &&$facts['network_mode']===$network['Name']&&array_keys($facts['networks'])===[$network['Name']],
            'M4C_H5_MS5_REAL_ORIGINAL_DB_CREATED_IDENTITY');
        m4c_entry_keys($formal['services'][$dbRole],['id','address','image_id']);
        m4c_expect($formal['services'][$dbRole]['id']===$facts['id']&&$formal['services'][$dbRole]['image_id']===$image
            &&is_string($formal['services'][$dbRole]['address'])
            &&filter_var($formal['services'][$dbRole]['address'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false,
            'M4C_H5_MS5_HISTORICAL_DB_IDENTITY_AND_IPV4');
        m4c_entry_same($manifest['services'][$dbRole],$formal['services'][$dbRole]);}
    $currentNames=$manifest['volumes'];sort($currentNames,SORT_STRING);$currentIdentities=$manifest['volume_identities'];
    if(in_array($actualPhase,['copy','register-prepare'],true)&&$currentNames===array_keys($volumes))
        m4c_entry_same($currentIdentities,$volumes);
    else{m4c_expect($currentNames===array_keys($allVolumes),'M4C_H5_MS5_CURRENT_EXACT_TEN_VOLUME_NAMES');
        m4c_entry_same($currentIdentities,$allVolumes);}
    $prepared=m4c_entry_terminal($source,'customer-prepare',M4C_MODE['prepare']['role'],[M4C_ENTRY,'prepare']);$ids[]=$prepared['id'];
    foreach($context['initial_service_ids']as$dbId)m4c_expect(!in_array($dbId,$ids,true),'M4C_H5_MS5_DB_AND_CALLER_PREDECESSORS_DISTINCT');
    $entryRows=array_values(array_filter($freeze['customer_source_files'],static fn(array $row):bool=>$row['path']===M4C_ENTRY));
    m4c_expect(count($entryRows)===1,'M4C_H5_MS5_ACTUAL_CUSTOMER_ENTRY_DESCRIPTOR');
    $prepareBinding=m4c_entry_read($e.M4C_MODE['prepare']['binding']);
    $wanted=['schema'=>1,'scope'=>M4C_MODE['prepare']['binding_scope'],'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
        'container_id'=>$prepared['id'],'native_created_sha256'=>$hashes['customer-prepare-created.json'],'freeze_sha256'=>$source['freeze_sha256'],
        'requested_repo_source'=>$context['repo_dir'],'requested_run_source'=>$context['run_dir'],'requested_prep_source'=>$context['prep_dir'],
        'entry_sha256'=>$entryRows[0]['sha256'],'source_files'=>$freeze['customer_source_files'],'prerequisites_sha256'=>$historyHashes];
    m4c_entry_same($prepareBinding,$wanted);
    m4c_entry_same(m4c_entry_environment($prepareBinding,$context,'customer-prepare'),$source['environment']);
    $base=m4c_entry_read($e.'ms4-customer-prepare.json');
    m4c_expect($base['schema']===1&&$base['scope']===M4C_MODE['prepare']['scope']&&$base['status']===M4C_MODE['prepare']['status']
        &&$base['run_id']===$source['run_id']&&$base['source_sha']===$source['source_sha']&&$base['container_id']===$prepared['id']
        &&$base['freeze_sha256']===$source['freeze_sha256']&&$base['native_created_sha256']===$hashes['customer-prepare-created.json']
        &&$base['source_binding_sha256']===m4c_entry_hash($e.M4C_MODE['prepare']['binding'])
        &&$base['environment_sha256']===m4c_entry_hash($e.M4C_MODE['prepare']['environment'])
        &&$base['phase']==='final_source'&&$base['native_exit']===0&&$base['full_source_unchanged']===true&&$base['child']===null
        &&$base['business']['HTTP_executed']===false&&$base['business']['order_creation_executed']===false
        &&$base['business']['fixture_counts']===['customers'=>1,'merchants'=>1,'products'=>2,'skus'=>3,'cart_rows'=>0,'orders'=>0],
        'M4C_H5_MS5_REAL_THIRTEEN_KEY_CUSTOMER_PREPARE_PREDECESSOR');
    if($actualPhase!=='copy'){
        $prior=m4ch_ms5_prior_binding($source,'copy');$ids[]=$prior['created']['id'];m4ch_ms5_public_copy($source,$prior);
        if(isset($source['public_copy']))m4ch_ms5_public_descriptor($source['public_copy'],$e.'customer-h5-public-copy.json',$e.'customer-h5-public-copy.json');
    }
    if(in_array($actualPhase,['register-files','files-bootstrap','register-final','serve'],true)){
        $prior=m4ch_ms5_prior_binding($source,'prepare');$ids[]=$prior['created']['id'];
        m4ch_ms5_public_descriptor($prior['binding']['public_copy'],$e.'customer-h5-public-copy.json',$e.'customer-h5-public-copy.json');
        $result=m4ch_ms5_extracted_result($source,'customer-h5-prepare',$prior);
        m4c_entry_keys($result,['schema','scope','status','run_id','source_sha','container_id','freeze_sha256','native_created_sha256',
            'source_binding_sha256','environment_sha256','public_copy_sha256','public_source_sha256','phase','native_exit','full_source_unchanged',
            'public_materials_unchanged','business','HTTP_executed','credential_login_authenticated','order_creation_executed','HTTP_DOM',
            'assertions','assertions_by_phase']);
        m4c_expect($result['scope']==='NORMAL_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
            &&$result['status']==='VERIFIED_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
            &&$result['environment_sha256']===m4c_entry_hash($e.'customer-h5-prepare-environment.json')
            &&$result['public_copy_sha256']===$hashes['customer-h5-public-copy.json']
            &&$result['public_source_sha256']===$freeze['h5_materials']['public_source']['sha256']
            &&$result['public_materials_unchanged']===true&&$result['HTTP_executed']===false
            &&$result['credential_login_authenticated']===false&&$result['order_creation_executed']===false&&$result['HTTP_DOM']==='NOT RUN'
            &&$result['business']['fixture_counts']===['customers'=>1,'merchants'=>2,'products'=>3,'skus'=>4,'cart_rows'=>0,'orders'=>0]
            &&$result['business']['business_table_count']===27&&$result['business']['auxiliary_table_count']===7
            &&$result['business']['base_fixture_preserved']===true
            &&$result['business']['production_configuration_published_and_read_back']===true
            &&$result['business']['theme_service_saved_enabled_and_read_back']===true
            &&$result['business']['language_native_ids_and_full_package_verified']===true,'M4C_H5_MS5_REAL_H3_PREPARATION_RESULT');
        if(isset($source['prepare_result']))m4ch_ms5_public_descriptor($source['prepare_result'],
            $actualPhase==='register-final'?$e.'customer-h5-prepare-result.json':'/run/audit-http-control/prepare-result.json',
            $e.'customer-h5-prepare-result.json');
    }
    if(in_array($actualPhase,['register-final','serve'],true)){
        $prior=m4ch_ms5_prior_binding($source,'files-bootstrap');$ids[]=$prior['created']['id'];
        m4ch_ms5_public_descriptor($prior['binding']['public_copy'],$e.'customer-h5-public-copy.json',$e.'customer-h5-public-copy.json');
        m4ch_ms5_public_descriptor($prior['binding']['prepare_result'],'/run/audit-http-control/prepare-result.json',$e.'customer-h5-prepare-result.json');
        m4c_expect($prior['binding']['registration_inputs']===null,'M4C_H5_MS5_HF3_NO_REGISTRATION_INPUTS');
        $result=m4ch_ms5_extracted_result($source,'customer-h5-files',$prior);$business=$result['business'];$installation=$business['installation'];
        m4c_entry_keys($result,['schema','scope','status','phase','run_id','source_sha','container_id','freeze_sha256','native_created_sha256',
            'source_binding_sha256','proof_name','full_source_unchanged','native_exit','business','HTTP_DOM']);
        m4c_expect($result['scope']==='CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
            &&$result['status']==='VERIFIED_CUSTOMER_H5_WITH_MS5_FILES_BOOTSTRAP_ONLY'
            &&$result['proof_name']==='customer-h5-files'&&$result['HTTP_DOM']==='NOT RUN'
            &&$business['business_tables']===27&&$business['auxiliary_tables']===7&&$business['complete_native_rows_unchanged']===true
            &&$business['sealed_baseline_and_base_fixture_unchanged']===true&&$business['DML_prepare_actual_terminal_zero_verified']===true
            &&$business['public_materials_unchanged']===true&&$business['HTTP_executed']===false
            &&$business['credential_login_authenticated']===false&&$business['order_creation_executed']===false
            &&$installation['account']==='audit_migrator'&&is_int($installation['checksum_rows'])&&$installation['checksum_rows']>0
            &&$installation['source_matches']===true&&$installation['version_marker_matches']===true
            &&$installation['application_account']==='audit_app'&&$installation['application_dml_only']===true,'M4C_H5_MS5_REAL_HF3_FILES_RESULT');
        if(isset($source['files_result']))m4ch_ms5_public_descriptor($source['files_result'],
            $actualPhase==='register-final'?$e.'customer-h5-files-result.json':'/run/audit-http-control/files-result.json',$e.'customer-h5-files-result.json');
    }
    if($actualPhase==='serve'){
        $prior=m4ch_ms5_prior_binding($source,'register-final');$ids[]=$prior['created']['id'];$binding=$prior['binding'];
        m4ch_ms5_public_descriptor($binding['public_copy'],$e.'customer-h5-public-copy.json',$e.'customer-h5-public-copy.json');
        foreach(['prepare','files']as$name)m4ch_ms5_public_descriptor($binding[$name.'_result'],
            $e.'customer-h5-'.$name.'-result.json',$e.'customer-h5-'.$name.'-result.json');
        foreach(['registration_inputs','network_receipts','stop_signal']as$key)m4c_entry_same($binding[$key],$source[$key]);
        m4c_entry_keys($binding['targets'],['serve','ui']);
        foreach(['serve'=>'customer-h5-serve','ui'=>'customer-h5-ui']as$name=>$targetProof)
            m4ch_ms5_public_descriptor($binding['targets'][$name],$e.$targetProof.'-created.json',$e.$targetProof.'-created.json');
        $result=m4c_entry_read($e.'register-customer-h5-serve-result.json');$roles=array_keys($manifest['services']);sort($roles,SORT_STRING);
        m4c_entry_keys($result,['schema','scope','status','phase','run_id','source_sha','container_id','freeze_sha256','native_created_sha256',
            'source_binding_sha256','proof_name','full_source_unchanged','native_exit','business','HTTP_DOM']);
        m4c_expect($roles===['audit-ms4-customer-h5','audit-mysql','audit-redis','audit-ui']
            &&$result['schema']===1&&$result['scope']==='CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
            &&$result['status']==='REGISTERED_CUSTOMER_H5_WITH_MS5_FINAL_RESOURCES_ONLY'&&$result['phase']==='register-final'
            &&$result['proof_name']==='register-customer-h5-serve'&&$result['run_id']===$source['run_id']
            &&$result['source_sha']===$source['source_sha']&&$result['container_id']===$prior['created']['id']
            &&$result['freeze_sha256']===$source['freeze_sha256']&&$result['native_exit']===0&&$result['full_source_unchanged']===true
            &&$result['native_created_sha256']===$hashes['register-customer-h5-serve-created.json']
            &&$result['source_binding_sha256']===m4c_entry_hash($e.$prior['binding_name'])&&$result['HTTP_DOM']==='NOT RUN'
            &&$result['business']['registered_roles']===$roles&&$result['business']['input_inventory_excludes_transient_helper']===true
            &&$result['business']['manifest_sha256']===m4c_entry_hash('/run/audit/manifest.json'),'M4C_H5_MS5_REAL_R3_FINAL_REGISTRATION_RESULT');
        m4ch_ms5_public_descriptor($source['registration_result'],$e.'register-customer-h5-serve-result.json',$e.'register-customer-h5-serve-result.json');
    }
    m4c_expect(count($ids)===count(array_unique($ids,SORT_STRING)),'M4C_H5_MS5_ALL_REAL_PREDECESSOR_CIDS_DISTINCT');
    foreach($names as$name)m4c_expect($hashes[$name]===m4c_entry_hash($e.$name),'M4C_H5_MS5_ALL_ORIGINAL_PHASE_BYTES_UNCHANGED');
    m4c_expect(m4c_entry_hash($contextPath)===$contextHash&&m4c_entry_read($contextPath)===$context
        &&m4c_entry_hash($freezePath)===$source['freeze_sha256']&&m4c_entry_read($freezePath)===$freeze
        &&m4c_entry_hash($createdPath)===$source['native_created_sha256']
        &&m4c_entry_hash($e.$bindingName)===$source['source_binding_sha256'],'M4C_H5_MS5_ORIGINAL_CONTEXT_FREEZE_AND_CALLER_BYTES_UNCHANGED');
}

/** Only three sealed destinations. Full contents and hashes never enter the public return. */
function m4ch_private_new(string $path,array $value): array
{
    m4c_expect(in_array($path,['/run/audit-http-control/sql-baseline.json',
        '/run/audit-http-control/customer-oracle.json','/run/audit-http-browser/customer-actors.json'],true),
        'M4C_H5_PRIVATE_FIXED_FILE');
    $parent=dirname($path);
    m4c_expect(function_exists('posix_geteuid')&&is_dir($parent)&&!is_link($parent)
        &&(fileperms($parent)&0777)===0700&&fileowner($parent)===posix_geteuid()
        &&!file_exists($path)&&!is_link($path),'M4C_H5_PRIVATE_CREATE_ONLY');
    $bytes=m4cb_json($value);
    m4c_expect(strlen($bytes)<=($path==='/run/audit-http-browser/customer-actors.json'?16384:2097152),
        'M4C_H5_PRIVATE_BOUNDED_FILE');
    $mask=umask(0077);$stream=false;
    try{
        $stream=fopen($path,'xb');m4c_expect(is_resource($stream),'M4C_H5_PRIVATE_EXCLUSIVE_OPEN');
        m4c_expect(chmod($path,0600)&&fwrite($stream,$bytes)===strlen($bytes)&&fflush($stream),
            'M4C_H5_PRIVATE_COMPLETE_WRITE');
    }finally{if(is_resource($stream))fclose($stream);umask($mask);}
    clearstatcache(true,$path);$stat=lstat($path);$read=file_get_contents($path);
    m4c_expect(is_array($stat)&&($stat['mode']&0170000)===0100000&&($stat['mode']&0777)===0600
        &&$stat['uid']===posix_geteuid()&&fileowner($path)===fileowner($parent)&&!is_link($path)
        &&is_string($read)&&$read===$bytes&&$stat['size']===strlen($bytes),'M4C_H5_PRIVATE_MODE_OWNER_AND_BYTES');
    return ['sha256'=>hash('sha256',$bytes),'prefix'=>substr(hash('sha256',$bytes),0,12),'bytes'=>strlen($bytes)];
}

/** Native configuration/language writes and real ThemeServices calls, then committed consumer readback. */
function m4ch_configuration(\think\App $app,array $source,array $themeInput,array $languageInput,array $auxiliaryBefore): array
{
    m4cb_phase('business_h5_configuration');
    m4c_expect(app()===$app&&!audit_db()->inTransaction(),'M4C_H5_CONFIGURATION_REAL_APP_AND_NATIVE_READER');
    $fixed=['site_name'=>'多商户商城','site_url'=>$source['h5']['runtime_origin'],
        'wap_login_logo'=>'/__ms4_fixture__/product.png','nncnL_crmeb_copyright'=>'通用多商户商城',
        'nncnL_crmeb_copyright_image'=>'/__ms4_fixture__/product.png','statistic_script'=>'','routine_auth_type'=>[],
        'balance_func_status'=>0,'recharge_switch'=>0,'ali_pay_status'=>0,'pay_weixin_open'=>0,
        'yue_pay_status'=>0,'friend_pay_status'=>0,'brokerage_func_status'=>0,
        'brokerage_window_switch'=>0,'brokerage_bindind'=>0];
    foreach($fixed as$key=>$value)m4c_expect(m4cb_rows(audit_db(),
        'SELECT * FROM `eb_system_config` WHERE `menu_name` = ?',[$key])===[],'M4C_H5_NEW_CONFIGURATION_KEYS_ABSENT');
    foreach(['eb_theme','eb_lang_type','eb_lang_country','eb_lang_code']as$table)
        m4c_expect(($auxiliaryBefore[$table]??null)===[],'M4C_H5_FRESH_THEME_AND_LANGUAGE_TABLES');

    $written=Db::transaction(static function(ConnectionInterface $connection)use($app,$fixed,$themeInput,$languageInput):array{
        $pdo=m4cb_transaction_pdo($connection);$configurationRows=[];
        foreach($fixed as$key=>$value)$configurationRows[]=m4cb_insert($pdo,'eb_system_config',
            ['menu_name'=>$key,'type'=>'text','value'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);

        // The three extra language tables have a closed, literal column contract; the shared insert whitelist stays intact.
        $insertLanguage=static function(PDO $native,string $table,array $values):array{
            $columns=match($table){'eb_lang_type'=>['language_name','file_name','status','is_default','is_del'],
                'eb_lang_country'=>['type_id','code','name'],'eb_lang_code'=>['type_id','code','remarks','lang_explain','is_admin'],
                default=>throw new RuntimeException('M4C_H5_ONLY_FIXED_LANGUAGE_TABLES')};
            m4c_expect(array_keys($values)===$columns,'M4C_H5_LANGUAGE_EXACT_INSERT_COLUMNS');
            $quoted=implode(',',array_map(static fn(string $name):string=>'`'.$name.'`',$columns));
            $statement=$native->prepare('INSERT INTO `'.$table.'` ('.$quoted.') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
            m4c_expect($statement instanceof PDOStatement,'M4C_H5_LANGUAGE_NATIVE_PREPARE');
            foreach(array_values($values)as$i=>$value)m4c_expect(is_scalar($value)&&$statement->bindValue($i+1,(string)$value,PDO::PARAM_STR),
                'M4C_H5_LANGUAGE_NATIVE_BIND');
            m4c_expect($statement->execute()&&$statement->rowCount()===1,'M4C_H5_LANGUAGE_NATIVE_INSERT');$id=$native->lastInsertId();
            m4c_expect(is_string($id)&&preg_match('/\A[1-9][0-9]*\z/D',$id)===1,'M4C_H5_LANGUAGE_DATABASE_GENERATED_PK');
            $rows=m4cb_rows($native,'SELECT * FROM `'.$table.'` WHERE `id` = ?',[$id]);
            m4c_expect(count($rows)===1&&(string)$rows[0]['id']===$id&&array_keys($rows[0])===['id',...$columns],
                'M4C_H5_LANGUAGE_COMPLETE_NATIVE_ROW');
            foreach($values as$name=>$value)m4c_expect((string)$rows[0][$name]===(string)$value,'M4C_H5_LANGUAGE_NATIVE_VALUE_READBACK');
            return $rows[0];
        };
        $type=$insertLanguage($pdo,'eb_lang_type',$languageInput['lang_type']['values']);
        $country=$insertLanguage($pdo,'eb_lang_country',['type_id'=>$type['id'],...$languageInput['lang_country']['values']]);
        $codes=[];
        foreach($languageInput['lang_code']['rows']as$item)$codes[]=$insertLanguage($pdo,'eb_lang_code',['type_id'=>$type['id'],...$item['values']]);
        m4c_expect(count($codes)===1179,'M4C_H5_COMPLETE_NATIVE_CHINESE_USER_ROWS');

        $themes=$app->make(ThemeServices::class);$began=time();
        $themeId=MarketplaceOrderInput::unsigned($themes->saveTheme(0,$themeInput['create']),'主题编号',2147483647);
        m4c_expect($themeId>0,'M4C_H5_THEME_ACTUAL_RETURNED_ID');
        $created=m4cb_rows($pdo,'SELECT * FROM `eb_theme` WHERE `id` = ?',[$themeId]);
        m4c_expect(count($created)===1,'M4C_H5_THEME_CREATED_NATIVE_ROW');$row=$created[0];$finished=time();
        foreach(['category_data_update_time','theme_data_update_time','add_time','up_time']as$field)
            m4c_expect((int)$row[$field]>=$began&&(int)$row[$field]<=$finished,'M4C_H5_THEME_ACTUAL_CREATE_TIMES');
        m4c_expect(is_string($row['version'])&&preg_match('/\A[a-f0-9]{13}\z/D',$row['version'])===1,'M4C_H5_THEME_ACTUAL_VERSION');
        $themeJson=json_encode($themeInput['create']['value']);
        $expected=['id'=>$themeId,'version'=>$row['version'],'title'=>$themeInput['create']['title'],'info'=>'','type'=>0,
            'home_data'=>null,'home_image'=>'','home_data_id'=>0,'home_data_update_time'=>0,
            'category_data'=>1,'category_image'=>'/statics/images/cate1.png','category_data_id'=>0,
            'category_data_update_time'=>$row['category_data_update_time'],
            'detail_data'=>null,'detail_image'=>'','detail_data_id'=>0,'detail_data_update_time'=>0,
            'user_data'=>null,'user_image'=>'','user_data_id'=>0,'user_data_update_time'=>0,
            'theme_data'=>$themeJson,'theme_data_id'=>0,'theme_data_update_time'=>$row['theme_data_update_time'],
            'home_default_data'=>null,'home_default_image'=>'','category_default_data'=>null,'category_default_image'=>'',
            'detail_default_data'=>null,'detail_default_image'=>'','user_default_data'=>null,'user_default_image'=>'',
            'theme_default_data'=>$themeJson,'page_type'=>'theme','is_use'=>0,'is_del'=>0,
            'add_time'=>$row['add_time'],'up_time'=>$row['up_time']];
        m4cb_equal_row($row,$expected);
        foreach($themeInput['updates']as$update){
            $began=time();$returned=MarketplaceOrderInput::unsigned($themes->saveTheme($themeId,$update),'主题编号',2147483647);
            m4c_expect($returned===$themeId&&m4cb_transaction_pdo($connection)===$pdo,'M4C_H5_THEME_SAME_REAL_ID_AND_TRANSACTION');
            $rows=m4cb_rows($pdo,'SELECT * FROM `eb_theme` WHERE `id` = ?',[$themeId]);
            m4c_expect(count($rows)===1,'M4C_H5_THEME_UPDATED_NATIVE_ROW');$next=$rows[0];$finished=time();$kind=$update['type'];
            m4c_expect((int)$next[$kind.'_data_update_time']>=$began&&(int)$next[$kind.'_data_update_time']<=$finished
                &&(int)$next['up_time']>=$began&&(int)$next['up_time']<=$finished
                &&is_string($next['version'])&&preg_match('/\A[a-f0-9]{13}\z/D',$next['version'])===1
                &&$next['version']!==$row['version'],'M4C_H5_THEME_REAL_UPDATE_TIME_AND_VERSION');
            $expected=$row;$expected[$kind.'_data']=json_encode($update['value']);
            $expected[$kind.'_default_data']=$expected[$kind.'_data'];
            $expected[$kind.'_data_update_time']=$next[$kind.'_data_update_time'];
            $expected['version']=$next['version'];$expected['up_time']=$next['up_time'];
            m4cb_equal_row($next,$expected);$row=$next;
        }
        m4c_expect($themes->useTheme($themeId)===true&&m4cb_transaction_pdo($connection)===$pdo,'M4C_H5_THEME_REAL_ENABLE');
        $rows=m4cb_rows($pdo,'SELECT * FROM `eb_theme` WHERE `id` = ?',[$themeId]);
        m4c_expect(count($rows)===1,'M4C_H5_THEME_ENABLED_NATIVE_ROW');$expected=$row;$expected['is_use']=1;
        m4cb_equal_row($rows[0],$expected);
        m4c_expect(m4cb_transaction_pdo($connection)===$pdo,'M4C_H5_CONFIGURATION_ONE_REAL_DEFAULT_TRANSACTION');
        return ['configuration_rows'=>$configurationRows,'language_rows'=>['eb_lang_type'=>[$type],
            'eb_lang_country'=>[$country],'eb_lang_code'=>$codes],'theme_id'=>$themeId,
            'theme_row'=>$rows[0]];
    });

    $pdo=audit_db();$parentPdo=Db::connect()->getPdo();
    m4c_expect($parentPdo instanceof PDO&&$pdo!==$parentPdo&&!$pdo->inTransaction()&&!$parentPdo->inTransaction(),
        'M4C_H5_COMMITTED_CONFIGURATION_NATIVE_READ');
    foreach($written['configuration_rows']as$row){$actual=m4cb_rows($pdo,'SELECT * FROM `eb_system_config` WHERE `id` = ?',[$row['id']]);
        m4c_expect(count($actual)===1,'M4C_H5_COMMITTED_CONFIGURATION_ROW');m4cb_equal_row($actual[0],$row);}
    $auxiliaryAfter=[];
    foreach(M4CH_AUX_TABLES as$table)$auxiliaryAfter[$table]=m4cb_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY `id`');
    foreach(M4CH_AUX_TABLES as$table){
        $added=$table==='eb_theme'?[$written['theme_row']]:($written['language_rows'][$table]??[]);
        $expected=array_map('m4cb_text_row',[...$auxiliaryBefore[$table],...$added]);
        m4c_expect(array_map('m4cb_text_row',$auxiliaryAfter[$table])===$expected,'M4C_H5_EXACT_COMMITTED_AUXILIARY_ROWS');
    }
    m4c_expect(CacheService::clear()===true,'M4C_H5_REAL_CONFIGURATION_CACHE_CLEAR');
    m4c_expect($app->make(LangCodeServices::class)->clearLangCache()===true,'M4C_H5_REAL_LANGUAGE_CACHE_PUBLICATION');
    $app->make(LangTypeServices::class)->setDefaultLangName();
    m4c_expect(CacheService::get('range_name')==='zh-CN','M4C_H5_REAL_DEFAULT_LANGUAGE_CACHE_READBACK');
    $configServices=$app->make(SystemConfigServices::class);
    foreach($fixed as$key=>$value){
        $rows=m4cb_rows($pdo,'SELECT * FROM `eb_system_config` WHERE `menu_name` = ?',[$key]);
        m4c_expect(count($rows)===1&&json_decode($rows[0]['value'],true,32,JSON_THROW_ON_ERROR)===$value
            &&$configServices->getConfigValue($key)===$value&&SystemConfigService::get($key)===$value,
            'M4C_H5_NATIVE_SERVICE_AND_PUBLISHED_CONFIGURATION');
        $expected=is_array($value)?$value:trim((string)$value);
        m4c_expect(sys_config($key)===$expected,'M4C_H5_REAL_SYS_CONFIG_CONSUMER');
    }
    m4c_expect(sys_config('routine_auth_type')===[]&&(int)sys_config('brokerage_func_status')===0
        &&(int)sys_config('brokerage_window_switch',1)===0&&(int)sys_config('brokerage_bindind')===0,
        'M4C_H5_REAL_ARRAY_AUTH_AND_DISABLED_MARKETING_CONSUMERS');

    $themes=$app->make(ThemeServices::class);$themeId=$written['theme_id'];
    m4c_expect($themes->getThemeVersion(0)===$written['theme_row']['version']
        &&$themes->getThemeInfo(0,'theme')===$themeInput['create']['value'],'M4C_H5_REAL_ACTIVE_THEME_CONSUMERS');
    $footer=null;
    foreach($themeInput['updates']as$update){
        m4c_expect($themes->getThemeInfo(0,$update['type'])===$update['value'],'M4C_H5_COMPLETE_SAVED_THEME_CONSUMER');
        if($update['type']==='home')foreach($update['value']['value']as$component)
            if(($component['name']??null)==='pageFoot')$footer=$component;
    }
    m4c_expect(is_array($footer)&&$footer!==[]&&$themes->themeNavigation()===$footer,'M4C_H5_REAL_FULL_PAGE_FOOT_CONSUMER');
    $typeId=MarketplaceOrderInput::unsigned($written['language_rows']['eb_lang_type'][0]['id'],'语言编号',2147483647);
    $types=$app->make(LangTypeServices::class);$countries=$app->make(LangCountryServices::class);$codes=$app->make(LangCodeServices::class);
    $typeMap=$types->getColumn(['status'=>1,'is_del'=>0],'file_name','id');
    m4c_expect(count($typeMap)===1&&($typeMap[$typeId]??null)==='zh-CN'&&$types->value(['is_default'=>1],'file_name')==='zh-CN'
        &&MarketplaceOrderInput::unsigned($countries->value(['code'=>'zh-CN'],'type_id'),'语言编号',2147483647)===$typeId,
        'M4C_H5_REAL_CHINESE_DEFAULT_AND_NATIVE_COUNTRY_BINDING');
    $expectedCodes=[];foreach($languageInput['lang_code']['rows']as$item)$expectedCodes[$item['values']['code']]=$item['values']['lang_explain'];
    $actualCodes=$codes->getColumn(['type_id'=>$typeId,'is_admin'=>0],'lang_explain','code');
    ksort($expectedCodes,SORT_STRING);ksort($actualCodes,SORT_STRING);
    m4c_expect(count($actualCodes)===1179&&$actualCodes===$expectedCodes,'M4C_H5_COMPLETE_REAL_CHINESE_PACKAGE_CONSUMER');
    $version=$codes->getLangVersion();m4c_expect(is_array($version)&&array_keys($version)===['version']
        &&is_string($version['version'])&&preg_match('/\A[a-f0-9]{13}\z/D',$version['version'])===1,'M4C_H5_REAL_LANGUAGE_VERSION_PRODUCER');
    foreach(M4CH_AUX_TABLES as$table)m4c_expect(m4cb_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY `id`')===$auxiliaryAfter[$table],
        'M4C_H5_CONSUMER_READS_PRESERVE_ALL_AUXILIARY_ROWS');
    return $written+['fixed_values'=>$fixed,'auxiliary_after'=>$auxiliaryAfter];
}

/** The caller supplies only its entry-verified actual identity, public-copy binding and two fixed input descriptors. */
function m4ch_prepare(array $source): array
{
    m4cb_phase('business_h5_inputs');$h5=$source['h5']??null;
    m4c_expect(is_array($h5),'M4C_H5_ENTRY_PROVIDED_REAL_PUBLIC_BINDING');$keys=array_keys($h5);sort($keys,SORT_STRING);
    m4c_expect($keys===['inputs','public_copy_sha256','public_source_sha256','runtime_origin']
        &&is_string($h5['runtime_origin'])&&preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D',$h5['runtime_origin'],$port)===1
        &&(int)$port[1]<=65535,'M4C_H5_FIXED_LOOPBACK_RUNTIME_ORIGIN');
    foreach(['public_copy_sha256','public_source_sha256']as$key)m4c_expect(is_string($h5[$key])
        &&preg_match('/\A[a-f0-9]{64}\z/D',$h5[$key])===1,'M4C_H5_ACTUAL_PUBLIC_SOURCE_AND_COPY_DIGEST_BINDINGS');
    m4c_expect(is_array($h5['inputs']),'M4C_H5_TWO_FIXED_INPUT_DESCRIPTORS');$keys=array_keys($h5['inputs']);sort($keys,SORT_STRING);
    m4c_expect($keys===['language','theme'],'M4C_H5_ONLY_LANGUAGE_AND_THEME_INPUTS');$inputs=[];
    foreach(['language'=>'/run/ms4-prep/inputs/language.json','theme'=>'/run/ms4-prep/inputs/theme.json']as$name=>$path){
        $descriptor=$h5['inputs'][$name];m4c_expect(is_array($descriptor),'M4C_H5_REAL_INPUT_DESCRIPTOR');
        $keys=array_keys($descriptor);sort($keys,SORT_STRING);
        m4c_expect($keys===['bytes','path','sha256']&&$descriptor['path']===$path&&is_int($descriptor['bytes'])
            &&$descriptor['bytes']>0&&$descriptor['bytes']<=2097152&&is_string($descriptor['sha256'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$descriptor['sha256'])===1&&is_file($path)&&!is_link($path),
            'M4C_H5_FIXED_BOUNDED_SOURCE_INPUT_FILE');
        $raw=file_get_contents($path);m4c_expect(is_string($raw)&&strlen($raw)===$descriptor['bytes']
            &&hash('sha256',$raw)===$descriptor['sha256'],'M4C_H5_EXACT_ENTRY_BOUND_INPUT_BYTES');
        $inputs[$name]=json_decode($raw,true,128,JSON_THROW_ON_ERROR);m4c_expect(is_array($inputs[$name]),'M4C_H5_REAL_INPUT_JSON_OBJECT');
    }
    $language=$inputs['language'];$theme=$inputs['theme'];
    m4c_expect(($language['schema']??null)===1&&($language['status']??null)==='PUBLIC_LANGUAGE_INPUT_ONLY_NOT_EXECUTED'
        &&($language['lang_type']['values']??null)===['language_name'=>'中文','file_name'=>'zh-CN','status'=>1,'is_default'=>1,'is_del'=>0]
        &&($language['lang_country']['values']??null)===['code'=>'zh-CN','name'=>'中文(简体) ']
        &&($language['lang_code']['row_count']??null)===1179&&is_array($language['lang_code']['rows']??null)
        &&array_is_list($language['lang_code']['rows'])&&count($language['lang_code']['rows'])===1179,
        'M4C_H5_COMPLETE_APPROVED_CHINESE_SEED_INPUT');
    $seen=[];
    foreach($language['lang_code']['rows']as$item){$values=$item['values']??null;
        m4c_expect(is_array($values)&&array_keys($values)===['code','remarks','lang_explain','is_admin']&&$values['is_admin']===0,
            'M4C_H5_LANGUAGE_INPUT_EXACT_VALUE_COLUMNS');
        foreach(['code','remarks','lang_explain']as$field)m4c_expect(is_string($values[$field])&&preg_match('//u',$values[$field])===1,
            'M4C_H5_LANGUAGE_INPUT_REAL_STRING_BOUNDARIES');
        m4c_expect($values['code']!==''&&!isset($seen[$values['code']]),'M4C_H5_LANGUAGE_INPUT_UNIQUE_CODE');$seen[$values['code']]=true;
    }
    m4c_expect(($theme['scope']??null)==='REAL_ISOLATED_THEME_SERVICE_INPUTS_ONLY'&&($theme['status']??null)==='NOT_APPLIED_NOT_RUN'
        &&($theme['create']['tid']??null)===0&&($theme['create']['type']??null)==='theme'&&($theme['create']['page_type']??null)==='theme'
        &&is_string($theme['create']['title']??null)&&$theme['create']['title']!==''
        &&($theme['create']['value']??null)===['theme_color'=>'#E93323','gradient_color'=>'#FF7931','sub_color'=>'#FE960F','light_color'=>'rgba(233, 51, 35, 0.1)']
        &&is_array($theme['updates']??null)&&array_is_list($theme['updates'])&&count($theme['updates'])===3,
        'M4C_H5_APPROVED_REAL_THEME_SERVICE_INPUT');
    foreach(['home','detail','user']as$i=>$kind){$update=$theme['updates'][$i];
        m4c_expect(($update['tid']??null)===0&&($update['title']??null)===''&&($update['type']??null)===$kind
            &&($update['page_type']??null)==='theme'&&is_array($update['value']??null)&&($update['value']['type']??null)===$kind
            &&is_array($update['value']['value']??null)&&$update['value']['value']!==[],'M4C_H5_COMPLETE_ORDERED_THEME_PAGE_INPUT');
    }

    $app=m4c_app('prepare',$source);
    foreach($language['lang_code']['rows']as$item)foreach(['code','remarks','lang_explain']as$field)
        m4c_expect(mb_strlen($item['values'][$field],'UTF-8')<=255,'M4C_H5_LANGUAGE_INPUT_REAL_STRING_BOUNDARIES');
    m4cb_guard_readonly();m4cb_phase('business_h5_base_fixture');
    $loaded=m4cb_fixture_load($source);$private=$loaded['private'];$base=$private['fixture'];
    $uid=MarketplaceOrderInput::unsigned($base['user']['uid'],'顾客编号');
    m4c_expect($uid>0&&count($base['products'])===2&&count($base['skus'])===3&&count($base['selected'])===2
        &&$base['user']['account']===$private['account']&&(int)$base['user']['is_money_level']===0,
        'M4C_H5_ACTUAL_BASE_CUSTOMER_AND_FIXTURE_COUNTS');
    foreach([['eb_user','uid',[$base['user']]],['eb_marketplace_merchant','mer_id',[$base['merchant']]],
        ['eb_store_product','id',array_values($base['products'])],['eb_store_product_attr_value','id',array_values($base['skus'])],
        ['eb_user_address','id',[$base['address']]],['eb_user_invoice','id',[$base['invoice']]]]as[$table,$pk,$expectedRows]){
        foreach($expectedRows as$row){$id=MarketplaceOrderInput::unsigned($row[$pk],'真实主键');
            m4c_expect($id>0,'M4C_H5_BASE_ACTUAL_POSITIVE_NATIVE_PK');
            $actual=m4cb_rows(audit_db(),'SELECT * FROM `'.$table.'` WHERE `'.$pk.'` = ?',[$id]);
            m4c_expect(count($actual)===1,'M4C_H5_BASE_ACTUAL_NATIVE_ROW_PRESENT');m4cb_equal_row($actual[0],$row);}
    }
    m4c_expect((int)$base['address']['uid']===$uid&&(int)$base['invoice']['uid']===$uid,'M4C_H5_BASE_REAL_OWNER_ADDRESS_AND_INVOICE');
    $pdo=audit_db();$connection=Db::connect();$parentPdo=$connection->getPdo();
    if(!$parentPdo instanceof PDO){$connection->query('SELECT 1',[],true);$parentPdo=$connection->getPdo();}
    m4c_expect($parentPdo instanceof PDO&&$pdo!==$parentPdo&&!$pdo->inTransaction()&&!$parentPdo->inTransaction()
        &&$connection===Db::connect()&&$parentPdo===Db::connect()->getPdo(),'M4C_H5_REAL_INDEPENDENT_NATIVE_BASELINE_READER');
    $before=m4cb_snapshot($pdo);$storefrontAdvanceBefore=m4ch_storefront_advance($pdo);$auxiliaryBefore=[];
    foreach(M4CH_AUX_TABLES as$table)$auxiliaryBefore[$table]=m4cb_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY `id`');
    foreach(['eb_store_cart','eb_store_order','eb_store_order_invoice','eb_store_order_cart_info',
        'eb_marketplace_order_idempotency','eb_marketplace_stock_movement']as$table)
        m4c_expect($before[$table]===[],'M4C_H5_BASE_HAS_NO_CART_OR_ORDER');
    $events=m4cb_events($pdo);
    m4c_expect(m4cb_rows($pdo,'SELECT * FROM `eb_system_event` WHERE `mark` = ? AND `is_del` = 0 AND `is_open` = 1',
        ['user_product_visit'])===[],'M4C_H5_NO_ENABLED_PRODUCT_VISIT_CUSTOM_EVENT');

    m4cb_phase('business_h5_second_store');
    $second=Db::transaction(static function(ConnectionInterface $connection):array{
        $native=m4cb_transaction_pdo($connection);$now=time();
        $merchant=m4cb_insert($native,'eb_marketplace_merchant',['name'=>'通用HTTP第二店铺','review_status'=>1,'accept_orders'=>1,
            'fulfillment_enabled'=>1,'security_locked'=>0,'after_sale_contact'=>'专用隔离售后','add_time'=>$now,'update_time'=>$now],'mer_id');
        $product=m4cb_insert($native,'eb_store_product',['mer_id'=>$merchant['mer_id'],'store_name'=>'通用HTTP商品丙','unit_name'=>'件',
            'spec_type'=>1,'stock'=>20,'sales'=>0,'price'=>'24.35','cost'=>'8.25','postage'=>'2.67','freight'=>2,'temp_id'=>0,
            'is_show'=>1,'review_status'=>2,'is_vip'=>0,'logistics'=>'1','min_qty'=>1,'is_limit'=>0,
            'image'=>'/__ms4_fixture__/product.png','slider_image'=>'["/__ms4_fixture__/product.png"]',
            'custom_form'=>json_encode([],JSON_THROW_ON_ERROR),'add_time'=>$now]);
        $attribute=m4cb_insert($native,'eb_store_product_attr',['product_id'=>$product['id'],'type'=>0,'attr_name'=>'规格','attr_values'=>'选中']);
        $sku=m4cb_insert($native,'eb_store_product_attr_value',['product_id'=>$product['id'],'type'=>0,'unique'=>bin2hex(random_bytes(4)),
            'suk'=>'选中','stock'=>20,'sales'=>0,'price'=>'24.35','cost'=>'8.25','is_show'=>1,
            'is_virtual'=>0,'weight'=>'0.00','volume'=>'0.00','image'=>'/__ms4_fixture__/product.png']);
        m4c_expect(m4cb_transaction_pdo($connection)===$native,'M4C_H5_SECOND_STORE_ONE_REAL_DEFAULT_TRANSACTION');
        return compact('merchant','product','attribute','sku');
    });
    foreach([['eb_marketplace_merchant','mer_id','merchant'],['eb_store_product','id','product'],
        ['eb_store_product_attr','id','attribute'],['eb_store_product_attr_value','id','sku']]as[$table,$pk,$name]){
        $actual=m4cb_rows($pdo,'SELECT * FROM `'.$table.'` WHERE `'.$pk.'` = ?',[$second[$name][$pk]]);
        m4c_expect(count($actual)===1,'M4C_H5_SECOND_STORE_COMMITTED_NATIVE_ROW');m4cb_equal_row($actual[0],$second[$name]);$second[$name]=$actual[0];
    }
    m4c_expect((int)$second['product']['mer_id']===(int)$second['merchant']['mer_id']
        &&(int)$second['product']['id']===(int)$second['sku']['product_id']&&(int)$second['sku']['type']===0
        &&(int)$second['merchant']['mer_id']!==(int)$base['merchant']['mer_id'],'M4C_H5_REAL_SECOND_STORE_PRODUCT_SKU_RELATION');
    $configuration=m4ch_configuration($app,$source,$theme,$language,$auxiliaryBefore);
    m4cb_phase('business_h5_complete_readback');$catalog=$app->make(PublicCatalogServices::class);$stores=[];
    foreach([...array_values($base['products']),$second['product']]as$product){
        $definition=$catalog->ordinaryPurchaseDefinition((int)$product['id'],$uid);
        m4c_expect(($definition['definition_complete']??null)===true&&(int)$definition['root']['id']===(int)$product['id']
            &&(int)$definition['root']['mer_id']===(int)$product['mer_id'],'M4C_H5_REAL_COMPLETE_PURCHASABLE_PRODUCT_DEFINITION');
        $store=PublicCatalogProjection::store($definition['root']);$stores[(int)$store['mer_id']]=$store;
        if((int)$product['id']===(int)$second['product']['id'])m4c_expect(count($definition['raw_values'])===1
            &&(int)$definition['raw_values'][$second['sku']['suk']]['id']===(int)$second['sku']['id'],
            'M4C_H5_REAL_SECOND_STORE_SKU_DEFINITION');
    }
    m4c_expect(count($stores)===2,'M4C_H5_TWO_ACTUAL_PUBLIC_STORE_PROJECTIONS');
    $after=m4cb_snapshot($pdo);$added=['eb_marketplace_merchant'=>[$second['merchant']],'eb_store_product'=>[$second['product']],
        'eb_store_product_attr'=>[$second['attribute']],'eb_store_product_attr_value'=>[$second['sku']],
        'eb_system_config'=>$configuration['configuration_rows']];
    foreach(M4CB_TABLES as$table)m4c_expect(array_map('m4cb_text_row',$after[$table])===
        array_map('m4cb_text_row',[...$before[$table],...($added[$table]??[])]),'M4C_H5_EXACT_ALL_TWENTY_SEVEN_PREPARATION_ROWS');
    foreach(M4CH_AUX_TABLES as$table)m4c_expect(m4cb_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY `id`')===
        $configuration['auxiliary_after'][$table],'M4C_H5_EXACT_ALL_SEVEN_AUXILIARY_BASELINES');
    m4c_expect(m4cb_events($pdo)===$events&&m4cb_rows($pdo,
        'SELECT * FROM `eb_system_event` WHERE `mark` = ? AND `is_del` = 0 AND `is_open` = 1',['user_product_visit'])===[],
        'M4C_H5_EVENT_AND_NOTIFICATION_PRECONDITIONS_PRESERVED');
    $again=m4cb_fixture_load($source);m4c_expect($again['sha256']===$loaded['sha256']&&$again['stat']===$loaded['stat']
        &&$again['prepare_receipt_sha256']===$loaded['prepare_receipt_sha256'],'M4C_H5_ORIGINAL_SEALED_FIXTURE_UNCHANGED');
    foreach($h5['inputs']as$descriptor)m4c_expect(is_file($descriptor['path'])&&!is_link($descriptor['path'])
        &&filesize($descriptor['path'])===$descriptor['bytes']&&hash_file('sha256',$descriptor['path'])===$descriptor['sha256'],
        'M4C_H5_SOURCE_INPUTS_AFTER_UNCHANGED');
    m4cb_guard_readonly();

    $fixture=$base;$fixture['merchants']=[(int)$base['merchant']['mer_id']=>$base['merchant'],
        (int)$second['merchant']['mer_id']=>$second['merchant']];
    $fixture['products'][(int)$second['product']['id']]=$second['product'];$fixture['skus'][(int)$second['sku']['id']]=$second['sku'];
    $secondLine=['product_id'=>(int)$second['product']['id'],'sku_id'=>(int)$second['sku']['id'],'qty'=>1];
    $formInputs=[];
    foreach($base['selected']as$i=>$line){$forms=json_decode($base['products'][$line['product_id']]['custom_form'],true,32,JSON_THROW_ON_ERROR);$fields=[];
        foreach($forms as$fieldIndex=>$field){m4c_expect(in_array($field['label'],['text','number'],true),'M4C_H5_REAL_BASE_FORM_INPUT_KIND');
            $fields[]=['field_index'=>$fieldIndex,'label'=>$field['label'],'value'=>$field['label']==='number'?'0':'顾客正常资料'.($i===0?'甲':'乙')];}
        $formInputs[]=['product_id'=>$line['product_id'],'fields'=>$fields];
    }
    $identity=['schema'=>1,'run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],
        'container_id'=>$source['container_id'],'native_created_sha256'=>$source['native_created_sha256'],
        'source_binding_sha256'=>$source['source_binding_sha256'],'public_copy_sha256'=>$h5['public_copy_sha256'],
        'public_source_sha256'=>$h5['public_source_sha256'],'runtime_origin'=>$h5['runtime_origin'],'input_bindings'=>$h5['inputs'],
        'environment'=>$source['environment'],'self_operated_mer_id'=>$source['self_operated_mer_id'],
        'base_fixture_sha256'=>$loaded['sha256'],'base_prepare_receipt_sha256'=>$loaded['prepare_receipt_sha256'],
        'base_prepare_container_id'=>$private['prepare_container_id'],
        'base_prepare_native_created_sha256'=>$private['prepare_native_created_sha256'],
        'base_prepare_source_binding_sha256'=>$private['prepare_source_binding_sha256']];
    $preparedSnapshot=['facts'=>$after,'auxiliary_rows'=>$configuration['auxiliary_after']];
    m4c_expect(m4ch_stage_snapshot($pdo)===$preparedSnapshot,'M4C_H5_PREPARE_FULL_NATIVE_BASELINE_BEFORE_SEAL');
    $baseline=$identity+['scope'=>'NORMAL_CUSTOMER_H5_SQL_BASELINE',
        'native_snapshot'=>m4ch_native_snapshot_archive($preparedSnapshot)];
    $oracle=$identity+['scope'=>'NORMAL_CUSTOMER_H5_PRIVATE_ORACLE','fixture'=>$fixture,'second_store'=>$second,
        'public_stores'=>$stores,'configuration_rows'=>$configuration['configuration_rows'],'configuration_values'=>$configuration['fixed_values'],
        'auxiliary_rows'=>$configuration['auxiliary_after'],
        'storefront_native_before'=>['schema'=>1,'advance_rows'=>$storefrontAdvanceBefore,'published'=>[]],
        'ui_intent'=>['cart_lines'=>[...$base['selected'],$secondLine],'checkout_mer_id'=>(int)$base['merchant']['mer_id'],
            'selected_lines'=>$base['selected'],'unselected_cart_line'=>$secondLine,'preserved_same_store_unselected_sku'=>$base['unselected'],
            'address_id'=>(int)$base['address']['id'],'invoice_id'=>(int)$base['invoice']['id'],
            'custom_form_inputs'=>$formInputs,'mark'=>'普通顾客页面下单','target_order_state'=>'unpaid']];
    m4c_expect(m4ch_storefront_advance($pdo)===$storefrontAdvanceBefore,'M4C_H5_STOREFRONT_PREPARE_NATIVE_ADVANCE_UNCHANGED');
    $oracle['storefront_native_before']['published']=m4ch_storefront_expected($after,$storefrontAdvanceBefore,$oracle);
    m4ch_storefront_oracle($oracle,$after);
    $actors=$identity+['scope'=>'NORMAL_CUSTOMER_H5_PRIVATE_ACTORS','actors'=>['customer'=>
        ['uid'=>$uid,'account'=>$private['account'],'password'=>$private['password']]]];
    $actorPath='/run/audit-http-browser/customer-actors.json';$actorProof=m4ch_private_new($actorPath,$actors);
    clearstatcache(true,$actorPath);$actorStat=lstat($actorPath);
    m4c_expect(is_array($actorStat)&&($actorStat['mode']&0170000)===0100000&&!is_link($actorPath)
        &&($actorStat['mode']&0777)===0600&&$actorStat['uid']===posix_geteuid()&&$actorStat['size']===$actorProof['bytes'],
        'M4C_H5_ACTORS_REAL_PRIVATE_DESCRIPTOR_FILE');
    $oracle['actor_descriptor']=['path'=>$actorPath,'sha256'=>$actorProof['sha256'],'bytes'=>$actorProof['bytes'],
        'owner_uid'=>$actorStat['uid'],'mode'=>'0600'];
    $sealed=[];
    foreach(['sql-baseline'=>['/run/audit-http-control/sql-baseline.json',$baseline],
        'customer-oracle'=>['/run/audit-http-control/customer-oracle.json',$oracle]]as$name=>[$path,$value]){
        $proof=m4ch_private_new($path,$value);$sealed[$name]=['sha256_prefix'=>$proof['prefix'],'bytes'=>$proof['bytes']];
    }
    $baselinePath='/run/audit-http-control/sql-baseline.json';$baselineMeta=m4ch_http_meta($baselinePath);
    $baselineRead=m4ch_http_private_read($baselinePath);
    m4ch_stage_keys($baselineRead,[...M4CH_STAGE_IDENTITY_KEYS,'scope','native_snapshot']);
    m4c_expect($baselineRead===$baseline&&m4ch_native_snapshot_restore($baselineRead['native_snapshot'])===$preparedSnapshot
        &&m4ch_stage_snapshot($pdo)===$preparedSnapshot,'M4C_H5_PREPARE_SEALED_NATIVE_BASELINE_AND_PDO_FULL_READBACK');
    m4c_expect(m4ch_http_meta($baselinePath)===$baselineMeta,'M4C_H5_PREPARE_SEALED_NATIVE_BASELINE_META_UNCHANGED');
    $sealed['customer-actors']=['sha256_prefix'=>$actorProof['prefix'],'bytes'=>$actorProof['bytes']];
    unset($private,$loaded,$again,$actors,$value);
    return ['fixture_counts'=>['customers'=>1,'merchants'=>2,'products'=>3,'skus'=>4,'cart_rows'=>0,'orders'=>0],
        'configuration_keys'=>array_keys($configuration['fixed_values']),'language_counts'=>['types'=>1,'countries'=>1,'user_codes'=>1179],
        'theme_count'=>1,'business_table_count'=>27,'auxiliary_table_count'=>7,'sealed_files'=>$sealed,
        'base_fixture_preserved'=>true,'native_rows_and_relationships_verified'=>true,'production_configuration_published_and_read_back'=>true,
        'theme_service_saved_enabled_and_read_back'=>true,'language_native_ids_and_full_package_verified'=>true,
        'event_preconditions'=>$events+['product_visit_custom_event_count'=>0],
        'HTTP_executed'=>false,'order_creation_executed'=>false,'credential_login_authenticated'=>false,'HTTP_DOM'=>'NOT RUN'];
}

/** Finite H5 readback. The entry owns source/live-parent gates and the only HTTP child. */
const M4CH_STAGE_CONTROL='/run/audit-http-control';
const M4CH_STAGE_IDENTITIES=['run_id','source_sha','container_id','freeze_sha256','epoch','step_nonce','not_before','deadline'];
const M4CH_STAGE_IDENTITY_KEYS=['schema','run_id','source_sha','freeze_sha256','container_id','native_created_sha256',
    'source_binding_sha256','public_copy_sha256','public_source_sha256','runtime_origin','input_bindings','environment',
    'self_operated_mer_id','base_fixture_sha256','base_prepare_receipt_sha256','base_prepare_container_id',
    'base_prepare_native_created_sha256','base_prepare_source_binding_sha256'];

function m4ch_stage_keys(array $value,array $keys): void
{
    $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);
    m4c_expect($actual===$keys,'M4C_H5_STAGE_EXACT_KEYS');
}
function m4ch_stage_hex(mixed $value,int $length=64): bool
{
    return is_string($value)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)===1;
}
function m4ch_stage_positive(mixed $value,int $maximum=4294967295): int
{
    m4c_expect((is_int($value)&&$value>0&&$value<=$maximum)||(is_string($value)
        &&preg_match('/\A[1-9][0-9]*\z/D',$value)===1&&strlen($value)<=strlen((string)$maximum)
        &&(strlen($value)<strlen((string)$maximum)||strcmp($value,(string)$maximum)<=0)), 'M4C_H5_STAGE_ACTUAL_POSITIVE_ID');
    return (int)$value;
}
function m4ch_stage_ref(mixed $value): string
{
    if(is_int($value)&&$value>0)$value=(string)$value;
    $maximum='18446744073709551615';
    m4c_expect(is_string($value)&&preg_match('/\A[1-9][0-9]*\z/D',$value)===1&&strlen($value)<=20
        &&(strlen($value)<20||strcmp($value,$maximum)<=0),'M4C_H5_STAGE_REAL_PERSISTENT_REFERENCE');return $value;
}
function m4ch_stage_refs(string $csv): array
{
    $refs=[];foreach(explode(',',$csv)as$value)$refs[]=m4ch_stage_ref($value);
    m4c_expect(count($refs)>0&&count($refs)<=500&&count(array_unique($refs,SORT_STRING))===count($refs),'M4C_H5_STAGE_UNIQUE_REFS');
    usort($refs,static fn(string$a,string$b):int=>strlen($a)<=>strlen($b)?:strcmp($a,$b));return $refs;
}
function m4ch_stage_shape(array $snapshot): void
{
    m4ch_stage_keys($snapshot,['facts','auxiliary_rows']);
    m4c_expect(count(M4CB_TABLES)===27&&count(M4CH_AUX_TABLES)===7
        &&array_keys($snapshot['facts'])===M4CB_TABLES&&array_keys($snapshot['auxiliary_rows'])===M4CH_AUX_TABLES,
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
function m4ch_stage_snapshot(PDO $pdo): array
{
    m4c_expect(!$pdo->inTransaction(),'M4C_H5_STAGE_REAL_NATIVE_READER_OUTSIDE_TRANSACTION');$auxiliary=[];
    foreach(M4CH_AUX_TABLES as$table)$auxiliary[$table]=m4cb_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY `id`');
    $snapshot=['facts'=>m4cb_snapshot($pdo),'auxiliary_rows'=>$auxiliary];m4ch_stage_shape($snapshot);return $snapshot;
}
function m4ch_stage_serialized(array $snapshot): array
{
    m4ch_stage_shape($snapshot);$bytes=serialize($snapshot);
    m4c_expect(strlen($bytes)<=1572864,'M4C_H5_STAGE_BOUNDED_NATIVE_SERIALIZATION');
    return ['snapshot_base64'=>base64_encode($bytes),'snapshot_sha256'=>hash('sha256',$bytes)];
}
function m4ch_stage_unserialize(array $stage): array
{
    m4c_expect(is_string($stage['snapshot_base64']??null)&&strlen($stage['snapshot_base64'])<=2097152
        &&m4ch_stage_hex($stage['snapshot_sha256']??null),'M4C_H5_STAGE_SEALED_NATIVE_ENCODING');
    $bytes=base64_decode($stage['snapshot_base64'],true);
    m4c_expect(is_string($bytes)&&strlen($bytes)>0&&strlen($bytes)<=1572864
        &&base64_encode($bytes)===$stage['snapshot_base64']&&hash('sha256',$bytes)===$stage['snapshot_sha256'],
        'M4C_H5_STAGE_FULL_NATIVE_SERIALIZED_DIGEST');
    $value=unserialize($bytes,['allowed_classes'=>false,'max_depth'=>16]);
    m4c_expect(is_array($value)&&serialize($value)===$bytes,'M4C_H5_STAGE_NATIVE_UNSERIALIZE_NO_OBJECT_OR_REWRITE');
    m4ch_stage_shape($value);return $value;
}
/** Exact native baseline envelope; the existing stage wire and size bounds stay unchanged. */
function m4ch_native_snapshot_archive(array $snapshot): array
{
    $stage=m4ch_stage_serialized($snapshot);$raw=base64_decode($stage['snapshot_base64'],true);
    m4c_expect(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=1572864,'M4C_H5_BASELINE_NATIVE_ARCHIVE_BYTES');
    $archive=['encoding'=>'php-serialize-base64-v1','bytes'=>strlen($raw),
        'sha256'=>$stage['snapshot_sha256'],'base64'=>$stage['snapshot_base64']];
    m4c_expect(m4ch_native_snapshot_restore($archive)===$snapshot,'M4C_H5_BASELINE_NATIVE_ARCHIVE_FULL_READBACK');
    return $archive;
}
function m4ch_native_snapshot_restore(array $archive): array
{
    m4ch_stage_keys($archive,['encoding','bytes','sha256','base64']);
    m4c_expect($archive['encoding']==='php-serialize-base64-v1'&&is_int($archive['bytes'])
        &&$archive['bytes']>0&&$archive['bytes']<=1572864,'M4C_H5_BASELINE_EXACT_NATIVE_ARCHIVE');
    $snapshot=m4ch_stage_unserialize(['snapshot_base64'=>$archive['base64'],'snapshot_sha256'=>$archive['sha256']]);
    $raw=base64_decode($archive['base64'],true);
    m4c_expect(is_string($raw)&&strlen($raw)===$archive['bytes'],'M4C_H5_BASELINE_ACTUAL_NATIVE_ARCHIVE_LENGTH');
    return $snapshot;
}
/** Extra native observation only: the original 27+7 snapshot and comparisons remain intact. */
function m4ch_storefront_advance(PDO $pdo): array
{
    $rows=m4cb_rows($pdo,'SELECT * FROM `eb_store_advance` ORDER BY `id`');
    m4c_expect(array_is_list($rows)&&count($rows)<=100000,'M4C_H5_STOREFRONT_FULL_NATIVE_ADVANCE_ROWS');$cells=0;
    foreach($rows as$row){m4c_expect(is_array($row)&&!array_is_list($row)&&count($row)<=512,'M4C_H5_STOREFRONT_NATIVE_ADVANCE_COLUMNS');
        foreach($row as$key=>$value)m4c_expect(is_string($key)&&preg_match('/\A[a-z_][a-z0-9_]*\z/D',$key)===1
            &&($value===null||is_string($value)||is_int($value)||is_bool($value)||(is_float($value)&&is_finite($value)))
            &&++$cells<=1000000,'M4C_H5_STOREFRONT_NATIVE_ADVANCE_SCALARS');}
    return $rows;
}
/** Independent BEFORE publication rules; no production catalog query/projector is called here. */
function m4ch_storefront_expected(array $facts,array $advance,array $oracle): array
{
    $enabled=$oracle['environment']['PHP_MARKETPLACE_ENABLED']??null;
    m4c_expect($enabled==='true'||$enabled==='false','M4C_H5_STOREFRONT_BOUND_PUBLICATION_CONFIGURATION');
    $a=m4ch_stage_positive($oracle['fixture']['merchant']['mer_id']);
    $b=m4ch_stage_positive($oracle['second_store']['merchant']['mer_id']);$owners=[$a,$b];$merchants=[];$reserved=[];
    foreach($facts['eb_marketplace_merchant']as$row){$id=m4ch_stage_positive($row['mer_id']);
        if(in_array($id,$owners,true))$merchants[$id]=$row;}
    m4c_expect(count($merchants)===2&&$a!==$b,'M4C_H5_STOREFRONT_BOTH_ORIGINAL_NATIVE_MERCHANTS');
    foreach($owners as$id)m4cb_equal_row($merchants[$id],$oracle['fixture']['merchants'][$id]);
    foreach($advance as$row)if(in_array($row['is_del']??null,[0,'0'],true)){
        $pid=$row['product_id']??null;if((is_int($pid)&&$pid>0)||(is_string($pid)&&preg_match('/\A[1-9][0-9]*\z/D',$pid)===1))
            $reserved[m4ch_stage_positive($pid)]=true;}
    $published=[];
    foreach($facts['eb_store_product']as$row){$owner=$row['mer_id']??null;
        if(!in_array($owner,[$a,(string)$a,$b,(string)$b],true))continue;$owner=(int)$owner;$merchant=$merchants[$owner];
        if(!in_array($row['review_status']??null,[2,'2'],true)||!in_array($row['is_show']??null,[1,'1'],true)
            ||!in_array($row['is_del']??null,[0,'0'],true)||!in_array($merchant['review_status']??null,[1,'1'],true)
            ||!in_array($merchant['security_locked']??null,[0,'0'],true)||!array_key_exists('closed_at',$merchant)||$merchant['closed_at']!==null
            ||($enabled==='false'&&$owner!==m4ch_stage_positive($oracle['self_operated_mer_id'])))continue;
        $pid=m4ch_stage_positive($row['id'],8388607);if(isset($reserved[$pid]))continue;
        foreach(['store_name','image','price']as$key)m4c_expect(is_string($row[$key])&&preg_match('//u',$row[$key])===1,
            'M4C_H5_STOREFRONT_NATIVE_PUBLIC_REFERENCE_VALUES');
        m4c_expect(is_int($row['sort'])||(is_string($row['sort'])&&preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D',$row['sort'])===1),
            'M4C_H5_STOREFRONT_NATIVE_SORT');
        $published[]=['product_id'=>$pid,'mer_id'=>$owner,'store_name'=>$row['store_name'],'image'=>$row['image'],
            'price'=>$row['price'],'sort'=>(int)$row['sort']];
    }
    usort($published,static fn(array$x,array$y):int=>$x['mer_id']<=>$y['mer_id']?:$y['sort']<=>$x['sort']?:$y['product_id']<=>$x['product_id']);
    foreach($owners as$id)m4c_expect(array_filter($published,static fn(array$row):bool=>$row['mer_id']===$id)!==[],
        'M4C_H5_STOREFRONT_ORIGINAL_NORMAL_SHOPS_HAVE_PUBLISHED_PRODUCTS');
    return $published;
}
function m4ch_storefront_oracle(array $oracle,array $facts): void
{
    $proof=$oracle['storefront_native_before'];m4ch_stage_keys($proof,['schema','advance_rows','published']);
    m4c_expect($proof['schema']===1&&is_array($proof['advance_rows'])&&array_is_list($proof['advance_rows'])
        &&is_array($proof['published'])&&array_is_list($proof['published'])
        &&$proof['published']===m4ch_storefront_expected($facts,$proof['advance_rows'],$oracle),
        'M4C_H5_STOREFRONT_RECOMPUTED_COMPLETE_NATIVE_BEFORE_PUBLICATION');
}
function m4ch_storefront_advance_equal(PDO $pdo,array $oracle): void
{
    m4c_expect(m4ch_storefront_advance($pdo)===$oracle['storefront_native_before']['advance_rows'],
        'M4C_H5_STOREFRONT_FULL_NATIVE_ADVANCE_BEFORE_REMAINS_UNCHANGED');
}
/** Fixed public wire fields from the agreed DTO contract, independent of the producer's projector. */
function m4ch_storefront_fields(mixed $value,array $allowed): void
{
    m4c_expect(is_array($value)&&($value===[]||!array_is_list($value))&&array_diff(array_keys($value),$allowed)===[],
        'M4C_H5_STOREFRONT_FIXED_PUBLIC_DTO_FIELDS');
}
function m4ch_storefront_no_cost(mixed $value): void
{
    if(!is_array($value))return;
    foreach($value as$key=>$item){m4c_expect(!is_string($key)||!in_array($key,['cost','cost_price','costPrice','original_cost','purchase_price'],true),
        'M4C_H5_STOREFRONT_NO_BACKEND_COST_IN_PUBLIC_DTO');m4ch_storefront_no_cost($item);}
}
/** Independent detail-only display expectation from the sealed actual native site_url. */
function m4ch_storefront_detail_image(string $image,array $oracle): string
{
    $siteUrl=$oracle['configuration_values']['site_url']??null;
    $rows=array_values(array_filter($oracle['configuration_rows'],static fn(array$row):bool=>($row['menu_name']??null)==='site_url'));
    m4c_expect(is_string($siteUrl)&&$siteUrl!==''&&trim($siteUrl)===$siteUrl&&$siteUrl===$oracle['runtime_origin']
        &&count($rows)===1&&is_string($rows[0]['value']??null)
        &&json_decode($rows[0]['value'],true,32,JSON_THROW_ON_ERROR)===$siteUrl,
        'M4C_H5_STOREFRONT_DETAIL_SEALED_NATIVE_SITE_URL_AND_REAL_ORIGIN');
    if(!$image||substr($image,0,4)==='http'||substr($image,0,2)==='//')return $image;
    return $siteUrl.str_replace('\\','/',$image);
}

function m4ch_storefront_public_product(array $value,?array $native,bool $detail=false): void
{
    $fields=['id','mer_id','store_name','store_info','image','slider_image','small_image','image_base','price','ot_price','vip_price','unit_name',
        'sales','fsales','stock','spec_type','is_postage','postage','is_vip','video_link','min_qty','limit_num','is_virtual','virtual_type',
        'presale','presale_start_time','presale_end_time','presale_day','presale_pay_status','cart_button','cart_num','is_att','labels','label_list',
        'activity','star','checkCoupon','cate_name'];
    if($detail)$fields=[...array_diff($fields,['activity','star']),'description','default_sku','unique','params_list','protection_list','userCollect',
        'userLike','command_word','wechat_code','attrPics','svip_economize_price','is_gift'];
    m4ch_storefront_fields($value,$fields);m4ch_storefront_no_cost($value);
    if($native!==null){m4c_expect(($value['id']??null)===$native['product_id']&&($value['mer_id']??null)===$native['mer_id']
            &&($value['store_name']??null)===$native['store_name']&&($value['image']??null)===$native['image'],
            'M4C_H5_STOREFRONT_PUBLIC_PRODUCT_EXACT_NATIVE_ID_OWNER_TITLE_IMAGE');
        if(!$detail)m4cb_public_money($value,'price',$native['price']);}
    foreach(['labels','label_list']as$key)if(isset($value[$key])){m4c_expect(is_array($value[$key])&&array_is_list($value[$key]),
        'M4C_H5_STOREFRONT_PUBLIC_LABEL_LIST');foreach($value[$key]as$row)m4ch_storefront_fields($row,['name','font_color','bg_color','border_color','image']);}
    foreach(['params_list'=>['name','value'],'protection_list'=>['id','title','content','image']]as$key=>$allowed)if(isset($value[$key])){
        m4c_expect(is_array($value[$key])&&array_is_list($value[$key]),'M4C_H5_STOREFRONT_PUBLIC_DETAIL_RESOURCE_LIST');
        foreach($value[$key]as$row)m4ch_storefront_fields($row,$allowed);}
    if(isset($value['activity'])){m4c_expect(is_array($value['activity']),'M4C_H5_STOREFRONT_PUBLIC_ACTIVITY_LINKS');
        $links=array_is_list($value['activity'])?$value['activity']:[$value['activity']];
        foreach($links as$row)m4ch_storefront_fields($row,['type','id','time_id']);}
}
function m4ch_storefront_public_store(mixed $value,int $id,array $oracle): void
{
    m4ch_storefront_fields($value,['mer_id','name','logo','after_sale_contact','shipping_note']);
    m4c_expect(count($value)===5&&($value['mer_id']??null)===$id,'M4C_H5_STOREFRONT_PUBLIC_STORE_EXACT_OWNER');
    foreach(['name','logo','after_sale_contact','shipping_note']as$key)m4c_expect(($value[$key]??null)===$oracle['fixture']['merchants'][$id][$key],
        'M4C_H5_STOREFRONT_PUBLIC_STORE_EXACT_NATIVE_VALUES');
}
/** Every real products page is checked, including repeats; coverage is the complete independently derived set. */
function m4ch_storefront_captures(array $stages,array $oracle): void
{
    $expected=$oracle['storefront_native_before']['published'];$byId=[];$byStore=[];$seen=[];$requests=[];
    foreach($expected as$row){$byId[$row['product_id']]=$row;$byStore[$row['mer_id']][]=$row;}
    foreach(m4ch_stage_captures($stages,'/api/products')as$capture){$query=[];parse_str(parse_url($capture['request']['url'],PHP_URL_QUERY)??'',$query);
        m4c_expect(array_diff(array_keys($query),['mer_id','page','limit'])===[]&&isset($query['mer_id'],$query['page'],$query['limit']),
            'M4C_H5_STOREFRONT_ACTUAL_EXPLICIT_PAGING_QUERY');$owner=m4ch_stage_positive($query['mer_id']);
        $page=m4ch_stage_positive($query['page'],100000);$limit=m4ch_stage_positive($query['limit'],1000);
        m4c_expect(isset($byStore[$owner]),'M4C_H5_STOREFRONT_ACTUAL_EXPECTED_SHOP');$data=m4ch_stage_data($capture);
        $slice=array_slice($byStore[$owner],($page-1)*$limit,$limit);
        m4c_expect(is_array($data)&&array_is_list($data)&&count($data)===count($slice),
            'M4C_H5_STOREFRONT_EXACT_COMPLETE_NATIVE_PAGE_LENGTH');
        foreach($data as$i=>$row){m4c_expect(is_array($row),'M4C_H5_STOREFRONT_PUBLIC_LIST_ROW');
            m4ch_storefront_public_product($row,$slice[$i]);$seen[$owner][$slice[$i]['product_id']]=true;}$requests[$owner]=true;
    }
    foreach($byStore as$owner=>$rows){$ids=array_column($rows,'product_id');$actual=array_keys($seen[$owner]??[]);sort($ids,SORT_NUMERIC);sort($actual,SORT_NUMERIC);
        m4c_expect(isset($requests[$owner])&&$actual===$ids,'M4C_H5_STOREFRONT_BOTH_SHOPS_COMPLETE_PUBLISHED_SET_CAPTURED');}
    foreach(m4ch_stage_captures($stages,'/api/marketplace/stores/:merchant')as$capture){$id=m4ch_stage_positive(basename(parse_url($capture['request']['url'],PHP_URL_PATH)));
        m4c_expect(isset($byStore[$id]),'M4C_H5_STOREFRONT_ACTUAL_STORE_ROUTE_OWNER');m4ch_storefront_public_store(m4ch_stage_data($capture),$id,$oracle);}
    foreach(m4ch_stage_captures($stages,'/api/product/detail/:product')as$capture){$data=m4ch_stage_data($capture);
        m4ch_storefront_fields($data,['storeInfo','mer_id','store','productAttr','productValue','spec_unique','svip_open','svip_price_open','priceName','reply',
            'replyCount','replyChance','good_list','mapKey','store_self_mention','activity','coupons','routine_contact_type']);m4ch_storefront_no_cost($data);
        $id=m4ch_stage_positive(basename(parse_url($capture['request']['url'],PHP_URL_PATH)),8388607);
        m4c_expect(isset($byId[$id])&&($data['mer_id']??null)===$byId[$id]['mer_id']&&is_array($data['storeInfo']??null),
            'M4C_H5_STOREFRONT_REAL_DETAIL_PUBLISHED_NATIVE_OWNER');
        $detailExpected=$byId[$id];$detailExpected['image']=m4ch_storefront_detail_image($detailExpected['image'],$oracle);
        m4ch_storefront_public_product($data['storeInfo'],$detailExpected,true);
        m4ch_storefront_public_store($data['store']??null,$byId[$id]['mer_id'],$oracle);
        m4c_expect(is_array($data['good_list']??null)&&array_is_list($data['good_list']),'M4C_H5_STOREFRONT_PUBLIC_DETAIL_RECOMMENDATIONS');
        foreach($data['good_list']as$row){m4c_expect(is_array($row),'M4C_H5_STOREFRONT_PUBLIC_RECOMMENDED_PRODUCT');
            m4ch_storefront_public_product($row,null);}
        if(is_array($data['reply']??null))m4ch_storefront_fields($data['reply'],['id','product_id','nickname','avatar','is_money_level','star',
            'add_time','suk','comment','pics','merchant_reply_content','merchant_reply_time']);
        m4c_expect(is_array($data['activity']??null)&&array_is_list($data['activity']),'M4C_H5_STOREFRONT_PUBLIC_DETAIL_ACTIVITY_LINKS');
        foreach($data['activity']as$row)m4ch_storefront_fields($row,['type','id','time_id']);
        m4c_expect(is_array($data['coupons']??null)&&array_is_list($data['coupons']),'M4C_H5_STOREFRONT_PUBLIC_DETAIL_COUPON_LIST');
        foreach($data['coupons']as$row)m4ch_storefront_fields($row,['id','title','type','receive_type','receive_limit','coupon_price','use_min_price',
            'coupon_time','start_use_time','end_use_time','start_time','end_time','is_use']);
        m4c_expect(is_array($data['productValue']??null),'M4C_H5_STOREFRONT_REAL_DETAIL_SKU_DICTIONARY');
        foreach($data['productValue']as$key=>$row){m4ch_storefront_fields($row,['id','product_id','type','unique','suk','image','price','ot_price','vip_price','stock','cart_num']);
            m4c_expect(m4ch_stage_positive($row['product_id']??null,8388607)===$id&&($row['suk']??null)===(string)$key,
                'M4C_H5_STOREFRONT_PUBLIC_SKU_REAL_PRODUCT_AND_KEY');}
        m4c_expect(is_array($data['productAttr']??null)&&array_is_list($data['productAttr']),'M4C_H5_STOREFRONT_REAL_DETAIL_ATTRIBUTES');
        foreach($data['productAttr']as$row){m4ch_storefront_fields($row,['attr_name','attr_values','attr_value']);
            m4c_expect(is_array($row['attr_value']??null)&&array_is_list($row['attr_value']),'M4C_H5_STOREFRONT_REAL_DETAIL_ATTRIBUTE_OPTIONS');
            foreach($row['attr_value']as$option)m4ch_storefront_fields($option,['attr','check','pic']);}
    }
}

function m4ch_stage_sealed(array $source): array
{
    $baselinePath=M4CH_STAGE_CONTROL.'/sql-baseline.json';$oraclePath=M4CH_STAGE_CONTROL.'/customer-oracle.json';
    $before=['baseline'=>m4ch_http_meta($baselinePath),'oracle'=>m4ch_http_meta($oraclePath)];
    $baseline=m4ch_http_private_read($baselinePath);$oracle=m4ch_http_private_read($oraclePath);
    m4ch_stage_keys($baseline,[...M4CH_STAGE_IDENTITY_KEYS,'scope','native_snapshot']);
    m4ch_stage_keys($oracle,[...M4CH_STAGE_IDENTITY_KEYS,'scope','fixture','second_store','public_stores',
        'configuration_rows','configuration_values','auxiliary_rows','storefront_native_before','ui_intent','actor_descriptor']);
    m4c_expect($baseline['schema']===1&&$baseline['scope']==='NORMAL_CUSTOMER_H5_SQL_BASELINE'
        &&$oracle['scope']==='NORMAL_CUSTOMER_H5_PRIVATE_ORACLE','M4C_H5_STAGE_ORIGINAL_SEALED_PRODUCER_SCOPES');
    $prepare=$source['prepare_identity'];m4ch_stage_keys($prepare,['container_id','native_created_sha256','source_binding_sha256','prepare_result_sha256']);
    foreach(M4CH_STAGE_IDENTITY_KEYS as$key)m4c_expect($baseline[$key]===$oracle[$key],'M4C_H5_STAGE_SAME_ORIGINAL_SEALED_IDENTITY');
    foreach(['run_id','source_sha','freeze_sha256','environment','self_operated_mer_id']as$key)
        m4c_expect($key==='environment'
            ? is_array($baseline[$key])&&is_array($source[$key])&&m4ch_http_same_environment($baseline[$key],$source[$key])
            : $baseline[$key]===$source[$key],'M4C_H5_STAGE_SEALED_SOURCE_IDENTITY');
    foreach(['container_id','native_created_sha256','source_binding_sha256']as$key)
        m4c_expect($baseline[$key]===$prepare[$key],'M4C_H5_STAGE_REAL_H5_PREPARE_IDENTITY');
    foreach(['public_copy_sha256','public_source_sha256','runtime_origin']as$key)
        m4c_expect($baseline[$key]===$source['h5'][$key],'M4C_H5_STAGE_SEALED_PUBLIC_MATERIAL_IDENTITY');
    m4c_expect($baseline['input_bindings']===$source['h5']['inputs']
        &&m4ch_http_meta(M4CH_STAGE_CONTROL.'/prepare-result.json')['sha256']===$prepare['prepare_result_sha256'],
        'M4C_H5_STAGE_ORIGINAL_INPUTS_AND_PREPARE_RESULT');
    $loaded=m4cb_fixture_load($source);$private=$loaded['private'];
    m4c_expect($baseline['base_fixture_sha256']===$loaded['sha256']
        &&$baseline['base_prepare_receipt_sha256']===$loaded['prepare_receipt_sha256']
        &&$baseline['base_prepare_container_id']===$private['prepare_container_id']
        &&$baseline['base_prepare_native_created_sha256']===$private['prepare_native_created_sha256']
        &&$baseline['base_prepare_source_binding_sha256']===$private['prepare_source_binding_sha256']
        &&$oracle['fixture']['user']===$private['fixture']['user']&&$oracle['fixture']['selected']===$private['fixture']['selected']
        &&$oracle['fixture']['merchant']===$private['fixture']['merchant']&&$oracle['fixture']['address']===$private['fixture']['address']
        &&$oracle['fixture']['invoice']===$private['fixture']['invoice'],'M4C_H5_STAGE_ORIGINAL_BASE_FIXTURE_RELATIONSHIPS');
    $snapshot=m4ch_native_snapshot_restore($baseline['native_snapshot']);
    m4c_expect($oracle['auxiliary_rows']===$snapshot['auxiliary_rows'],'M4C_H5_STAGE_FULL_PREPARED_NATIVE_BASELINE');
    m4ch_storefront_oracle($oracle,$snapshot['facts']);
    m4c_expect($before===['baseline'=>m4ch_http_meta($baselinePath),'oracle'=>m4ch_http_meta($oraclePath)],
        'M4C_H5_STAGE_SEALED_FILES_REMAIN_ORIGINAL');unset($private,$loaded);
    return ['baseline'=>$snapshot,'oracle'=>$oracle,'metadata'=>$before];
}
/** Exact UI-only private handoff; no cost/configuration/authentication material leaves the oracle. */
function m4ch_browser_intent(array $oracle): array
{
    $fixture=$oracle['fixture'];$intent=$oracle['ui_intent'];$lines=$intent['cart_lines'];
    m4c_expect(is_array($lines)&&array_is_list($lines)&&count($lines)===3&&count($fixture['selected'])===2,
        'M4C_H5_STAGE_THREE_REAL_UI_PRODUCT_LINES');
    $a=m4ch_stage_positive($fixture['merchant']['mer_id']);$b=m4ch_stage_positive($oracle['second_store']['merchant']['mer_id']);
    m4c_expect($a!==$b&&(int)$intent['checkout_mer_id']===$a&&$intent['selected_lines']===$fixture['selected']
        &&$lines===[...$fixture['selected'],$intent['unselected_cart_line']]
        &&$intent['unselected_cart_line']===['product_id'=>(int)$oracle['second_store']['product']['id'],
            'sku_id'=>(int)$oracle['second_store']['sku']['id'],'qty'=>1], 'M4C_H5_STAGE_REAL_TWO_STORE_UI_INTENT');
    $products=[];$skuPairs=[];$productIds=[];
    foreach($lines as$i=>$line){m4ch_stage_keys($line,['product_id','sku_id','qty']);
        $pid=m4ch_stage_positive($line['product_id'],8388607);$sid=m4ch_stage_positive($line['sku_id'],2147483647);
        $product=$fixture['products'][$pid]??null;$sku=$fixture['skus'][$sid]??null;
        m4c_expect(is_array($product)&&is_array($sku)&&(int)$product['id']===$pid&&(int)$sku['id']===$sid
            &&(int)$sku['product_id']===$pid&&(int)$sku['type']===0&&(int)$product['mer_id']===($i<2?$a:$b)
            &&$line['qty']===($i===0?2:1)&&is_string($sku['unique'])&&preg_match('/\A[A-Za-z0-9]{8}\z/D',$sku['unique'])===1
            &&is_string($sku['suk'])&&$sku['suk']!==''&&is_string($product['store_name'])&&$product['store_name']!=='',
            'M4C_H5_STAGE_REAL_UI_ROOT_SKU_QUANTITY');
        $products[]=['product_id'=>$pid,'store_name'=>$product['store_name'],'mer_id'=>(int)$product['mer_id'],
            'sku_id'=>$sid,'unique'=>$sku['unique'],'suk'=>$sku['suk'],'qty'=>$line['qty'],'selected'=>$i<2];
        $productIds[]=$pid;$skuPairs[]=['productId'=>$pid,'unique'=>$sku['unique']];
    }
    m4c_expect(count(array_unique($productIds))===3,'M4C_H5_STAGE_THREE_DISTINCT_UI_PRODUCTS');
    $address=[];foreach(['id','real_name','phone','province','city','district','detail']as$key)$address[$key]=$fixture['address'][$key];
    $invoice=[];foreach(['id','name','header_type','type']as$key)$invoice[$key]=$fixture['invoice'][$key];
    $address['id']=m4ch_stage_positive($address['id'],16777215);$invoice['id']=m4ch_stage_positive($invoice['id'],2147483647);
    m4c_expect($address['id']===$intent['address_id']&&$invoice['id']===$intent['invoice_id']
        &&$intent['target_order_state']==='unpaid'&&is_string($intent['mark'])&&$intent['mark']!=='',
        'M4C_H5_STAGE_REAL_OWNED_UI_ADDRESS_INVOICE');
    $forms=[];m4c_expect(is_array($intent['custom_form_inputs'])&&array_is_list($intent['custom_form_inputs'])
        &&count($intent['custom_form_inputs'])===2,'M4C_H5_STAGE_EXACT_TWO_UI_FORM_INTENTS');
    foreach($intent['custom_form_inputs']as$i=>$group){m4ch_stage_keys($group,['product_id','fields']);
        m4c_expect($group['product_id']===$products[$i]['product_id']&&is_array($group['fields'])&&array_is_list($group['fields'])
            &&count($group['fields'])===($i===0?2:1),'M4C_H5_STAGE_UI_FORM_INTENT_PRODUCT');$fields=[];
        foreach($group['fields']as$j=>$field){m4ch_stage_keys($field,['field_index','label','value']);
            m4c_expect($field['field_index']===$j&&in_array($field['label'],['text','number'],true)&&is_string($field['value'])
                &&strlen($field['value'])<=512&&preg_match('//u',$field['value'])===1,'M4C_H5_STAGE_FINITE_UI_FORM_VALUE');
            $fields[]=['field_index'=>$j,'label'=>$field['label'],'value'=>$field['value']];}
        $forms[]=['product_id'=>$group['product_id'],'fields'=>$fields];}
    return ['public_ids'=>['merchantIds'=>[$a,$b],'productIds'=>$productIds,'addressIds'=>[$address['id']],
        'invoiceIds'=>[$invoice['id']],'skuPairs'=>$skuPairs],
        'ui_intent'=>['customer_uid'=>m4ch_stage_positive($fixture['user']['uid']),'checkout_mer_id'=>$a,
            'stores'=>[['mer_id'=>$a,'name'=>$fixture['merchant']['name']],['mer_id'=>$b,'name'=>$oracle['second_store']['merchant']['name']]],
            'products'=>$products,'storefront_products'=>array_map(static fn(array$row):array=>[
                'product_id'=>$row['product_id'],'mer_id'=>$row['mer_id'],'store_name'=>$row['store_name']],
                $oracle['storefront_native_before']['published']),
            'address'=>$address,'invoice'=>$invoice,'custom_form_inputs'=>$forms,
            'mark'=>$intent['mark'],'target_order_state'=>'unpaid']];
}
function m4ch_stage_target(string $url,string $method,mixed $body,array $source,array $oracle,?string $postData=null): string
{
    $origin=$source['h5']['runtime_origin'];$parts=parse_url($url);
    m4c_expect(is_array($parts)&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['fragment'])
        &&($parts['scheme']??null)==='http'&&($parts['host']??null)==='127.0.0.1'
        &&$origin==='http://127.0.0.1:'.($parts['port']??0),'M4C_H5_STAGE_ACTUAL_SAME_ORIGIN_API');
    $path=$parts['path']??'';$query=[];
    if(isset($parts['query']))foreach(explode('&',$parts['query'])as$pair){$kv=explode('=',$pair,2);
        $key=rawurldecode(str_replace('+',' ',$kv[0]));$value=rawurldecode(str_replace('+',' ',$kv[1]??''));
        m4c_expect(preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$key)===1&&!array_key_exists($key,$query)
            &&strlen($value)<=2048&&!str_contains($value,"\0"),'M4C_H5_STAGE_FINITE_QUERY_SCALARS');$query[$key]=$value;}
    $ids=m4ch_browser_intent($oracle)['public_ids'];$label=null;$allowedQuery=[];
    $gets=['basic_config','theme_info/theme','theme_info/detail','lang_version','get_lang_json','copyright','wechat/get_logo',
        'theme/navigation','v2/diy/get_version/0','user','cart/count','cart/list','product/hot','v2/invoice','address/default',
        'address/list','get_customer_type','get_script'];
    $posts=['image_base64','cart/add','cart/num','order/check_shipping','order/confirm'];
    if($method==='GET'&&in_array(substr($path,5),$gets,true)&&str_starts_with($path,'/api/')){
        $label=$path;$allowedQuery=match($path){'/api/cart/count'=>['numType'],'/api/cart/list'=>['page','limit','status'],
            '/api/product/hot','/api/address/list'=>['page','limit'],default=>[]};
    }elseif($method==='POST'&&in_array(substr($path,5),$posts,true)&&str_starts_with($path,'/api/'))$label=$path;
    elseif($method==='GET'&&$path==='/api/products'){
        m4c_expect(isset($query['mer_id'])&&in_array(m4ch_stage_positive($query['mer_id']),$ids['merchantIds'],true),
            'M4C_H5_STAGE_ACTUAL_STORE_PRODUCTS_QUERY');$label=$path;$allowedQuery=['mer_id','page','limit'];
    }elseif($method==='GET'&&preg_match('~\A/api/marketplace/stores/([1-9][0-9]*)\z~D',$path,$m)===1){
        m4c_expect(in_array(m4ch_stage_positive($m[1]),$ids['merchantIds'],true),'M4C_H5_STAGE_REAL_STORE_PATH');$label='/api/marketplace/stores/:merchant';
    }elseif($method==='GET'&&preg_match('~\A/api/product/detail/([1-9][0-9]*)\z~D',$path,$m)===1){
        m4c_expect(in_array(m4ch_stage_positive($m[1]),$ids['productIds'],true),'M4C_H5_STAGE_REAL_PRODUCT_PATH');$label='/api/product/detail/:product';
    }elseif($method==='GET'&&preg_match('~\A/api/product/real_price/([1-9][0-9]*)/([A-Za-z0-9]{8})\z~D',$path,$m)===1){
        m4c_expect(in_array(['productId'=>m4ch_stage_positive($m[1]),'unique'=>$m[2]],$ids['skuPairs'],true),
            'M4C_H5_STAGE_REAL_SKU_PRICE_PATH');$label='/api/product/real_price/:product/:sku';
    }elseif($method==='GET'&&preg_match('~\A/api/address/detail/([1-9][0-9]*)\z~D',$path,$m)===1){
        m4c_expect(in_array(m4ch_stage_positive($m[1]),$ids['addressIds'],true),'M4C_H5_STAGE_REAL_OWNED_ADDRESS_PATH');$label='/api/address/detail/:address';
    }elseif($method==='POST'&&preg_match('~\A/api/order/confirmation/([^/]+)(/restore)?\z~D',$path,$m)===1){
        $key=rawurldecode($m[1]);$encoded=str_replace(['%21','%27','%28','%29','%2A'],['!',"'",'(',')','*'],rawurlencode($key));
        m4c_expect(preg_match('/\A[\x21-\x7e]{1,32}\z/D',$key)===1&&$encoded===$m[1],
            'M4C_H5_STAGE_CANONICAL_RESTORE_ROUTE_KEY');
        $label='/api/order/confirmation/:confirmation'.(isset($m[2])?'/restore':'');
        if(isset($m[2])){
            m4c_expect(is_array($body)&&!array_is_list($body),'M4C_H5_STAGE_REAL_RESTORE_OBJECT');
            m4ch_stage_keys($body,['addressId','invoice_id','payType']);
            foreach(['addressId'=>'addressIds','invoice_id'=>'invoiceIds']as$field=>$bound){$value=$body[$field];
                m4c_expect((is_int($value)||is_string($value))&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$value)===1
                    &&((string)$value==='0'||in_array(m4ch_stage_positive($value),$ids[$bound],true)),
                    'M4C_H5_STAGE_RESTORE_OWN_ID_OR_EXPLICIT_NONE');}
            m4c_expect($body['payType']==='','M4C_H5_STAGE_NORMAL_RESTORE_ORIGINAL_NO_CHANNEL_INTENT');
        }else m4c_expect($body===[]&&is_string($postData)
            &&preg_match('/\A[ \t\r\n]*\{[ \t\r\n]*\}[ \t\r\n]*\z/D',$postData)===1,
            'M4C_H5_STAGE_LOOKUP_ORIGINAL_EMPTY_JSON_OBJECT');
    }elseif($method==='POST'&&preg_match('~\A/api/order/(computed|create)/([^/]+)\z~D',$path,$m)===1){
        $key=rawurldecode($m[2]);m4c_expect(preg_match('/\A[\x21-\x7e]{1,32}\z/D',$key)===1,'M4C_H5_STAGE_ACTUAL_CONFIRMATION_ROUTE_KEY');
        $label='/api/order/'.$m[1].'/:confirmation';
    }elseif($method==='GET'&&preg_match('~\A/api/order/detail/([^/]+)\z~D',$path,$m)===1){
        $number=rawurldecode($m[1]);m4c_expect($number!==''&&strlen($number)<=64&&!str_contains($number,"\0"),
            'M4C_H5_STAGE_ACTUAL_ORDER_DETAIL_PATH');$label='/api/order/detail/:order';
    }
    m4c_expect(is_string($label)&&array_diff(array_keys($query),$allowedQuery)===[],'M4C_H5_STAGE_ONLY_NORMAL_BOUND_API');
    foreach($query as$key=>$value){if(in_array($key,['page','limit'],true))m4ch_stage_positive($value,100);
        elseif($key==='status')m4c_expect(in_array($value,['0','1'],true),'M4C_H5_STAGE_NORMAL_CART_STATUS');
        elseif($key==='numType')m4c_expect($value==='0','M4C_H5_STAGE_NORMAL_CART_COUNT_TYPE');}
    if($method==='GET')m4c_expect($body===null,'M4C_H5_STAGE_REAL_GET_WITHOUT_BODY');
    else m4c_expect(($label==='/api/order/confirmation/:confirmation'&&$body===[])
        ||(is_array($body)&&!array_is_list($body)),'M4C_H5_STAGE_REAL_POST_OBJECT');
    return $label;
}
function m4ch_stage_network(array $request,array $source,array $oracle,array $oldStages): void
{
    $network=$request['network'];$captures=$request['captures'];$stage=$request['stage'];
    m4c_expect(is_array($network)&&array_is_list($network)&&count($network)<=4096&&is_array($captures)
        &&array_is_list($captures)&&count($captures)<=4096,'M4C_H5_STAGE_BOUNDED_NETWORK_COLLECTION');$seen=[];
    foreach($oldStages as$old)foreach($old['network']as$row)$seen[$row['request_id']]=true;
    $byId=[];$login=0;
    foreach($network as$row){m4c_expect(is_array($row),'M4C_H5_STAGE_NETWORK_ROW');m4ch_stage_keys($row,['request_id','projection']);
        $id=$row['request_id'];$p=$row['projection'];m4c_expect(is_string($id)&&$id!==''&&strlen($id)<=256&&!isset($seen[$id])
            &&!isset($byId[$id])&&is_array($p),'M4C_H5_STAGE_UNIQUE_ACTUAL_REQUEST_ID');
        m4ch_stage_keys($p,['scope','method','path_template','http_status','response_kind','business_status','nested_status',
            'response_bytes','response_sha256_prefix','private_capture_sha256_prefix']);
        m4c_expect($p['scope']==='NETWORK_ONLY_NOT_DOM_ACTION_ACK_JPEG_OR_UI_ACCEPTANCE'
            &&in_array($p['method'],['GET','POST'],true)&&$p['http_status']===200
            &&is_string($p['path_template'])&&is_int($p['response_bytes'])&&$p['response_bytes']>=0&&$p['response_bytes']<=2097152
            &&m4ch_stage_hex($p['response_sha256_prefix'],12)&&m4ch_stage_hex($p['private_capture_sha256_prefix'],12),
            'M4C_H5_STAGE_ACTUAL_SAFE_NETWORK_PROJECTION');
        if($p['path_template']==='/api/get_script')m4c_expect($p['response_kind']==='raw_text'&&$p['business_status']===null
            &&$p['nested_status']===null,'M4C_H5_STAGE_REAL_RAW_STATISTICS_TEXT');
        else m4c_expect($p['response_kind']==='json'&&$p['business_status']===200,'M4C_H5_STAGE_REAL_BUSINESS_SUCCESS');
        $getTemplates=['/api/basic_config','/api/theme_info/theme','/api/theme_info/detail','/api/lang_version','/api/get_lang_json',
            '/api/copyright','/api/wechat/get_logo','/api/theme/navigation','/api/v2/diy/get_version/0','/api/user','/api/cart/count',
            '/api/cart/list','/api/product/hot','/api/v2/invoice','/api/address/default','/api/address/list','/api/get_customer_type',
            '/api/get_script','/api/products','/api/marketplace/stores/:merchant','/api/product/detail/:product',
            '/api/product/real_price/:product/:sku','/api/address/detail/:address','/api/order/detail/:order'];
        $postTemplates=match($stage){'B1'=>['/api/login'],'B2'=>['/api/image_base64','/api/cart/add','/api/cart/num'],
            'B3'=>['/api/image_base64','/api/order/check_shipping','/api/order/confirm','/api/order/confirmation/:confirmation',
                '/api/order/confirmation/:confirmation/restore','/api/order/computed/:confirmation'],
            'B4'=>['/api/order/create/:confirmation'],default=>[]};
        m4c_expect(in_array($p['path_template'],$p['method']==='GET'?$getTemplates:$postTemplates,true),
            'M4C_H5_STAGE_FINITE_ACTUAL_PROJECTION_API_PHASE');
        if(!in_array($p['path_template'],['/api/order/computed/:confirmation','/api/order/create/:confirmation'],true))
            m4c_expect($p['nested_status']===null,'M4C_H5_STAGE_NORMAL_NON_NESTED_PROJECTION');
        if($p['path_template']==='/api/login'){++$login;m4c_expect($stage==='B1'&&$p['method']==='POST'
            &&$p['nested_status']===null,'M4C_H5_STAGE_LOGIN_PROJECTION_ONLY');}
        $byId[$id]=$p;
    }
    $captured=[];
    foreach($captures as$capture){m4c_expect(is_array($capture),'M4C_H5_STAGE_PRIVATE_CAPTURE_OBJECT');
        m4ch_stage_keys($capture,['scope','request','response','path_template','business_status','nested_status','private_capture_sha256']);
        $wire=$capture['request'];$response=$capture['response'];m4c_expect(is_array($wire)&&is_array($response),'M4C_H5_STAGE_ACTUAL_WIRE_OBJECTS');
        m4ch_stage_keys($wire,['request_id','url','method','post_data','body']);
        m4ch_stage_keys($response,['http_status','mime_type','body_bytes','body_sha256','body_base64','text','envelope']);
        $id=$wire['request_id'];m4c_expect(is_string($id)&&isset($byId[$id])&&!isset($captured[$id])
            &&$capture['scope']==='PRIVATE_ACTUAL_CUSTOMER_NETWORK_ONLY'&&m4ch_stage_hex($capture['private_capture_sha256'])
            &&is_string($wire['url'])&&strlen($wire['url'])<=8192&&in_array($wire['method'],['GET','POST'],true),
            'M4C_H5_STAGE_ACTUAL_PRIVATE_OBSERVER_CAPTURE');
        if($wire['method']==='POST')m4c_expect(is_string($wire['post_data'])&&strlen($wire['post_data'])<=2097152
            &&json_decode($wire['post_data'],true,128,JSON_THROW_ON_ERROR)===$wire['body'],
            'M4C_H5_STAGE_ORIGINAL_POST_DATA_PARSED_WITHOUT_RECONSTRUCTION');
        else m4c_expect($wire['post_data']===null&&$wire['body']===null,'M4C_H5_STAGE_ACTUAL_EMPTY_GET_BODY');
        $label=m4ch_stage_target($wire['url'],$wire['method'],$wire['body'],$source,$oracle,$wire['post_data']);
        m4c_expect($capture['path_template']===$label&&$label!=='/api/login','M4C_H5_STAGE_NO_AUTH_BODY_OR_TOKEN_CAPTURE');
        $raw=base64_decode($response['body_base64'],true);
        m4c_expect(is_string($raw)&&base64_encode($raw)===$response['body_base64']&&is_int($response['body_bytes'])
            &&strlen($raw)===$response['body_bytes']&&strlen($raw)<=2097152&&m4ch_stage_hex($response['body_sha256'])
            &&hash('sha256',$raw)===$response['body_sha256']&&$raw===$response['text']&&preg_match('//u',$raw)===1
            &&$response['http_status']===200&&is_string($response['mime_type']),'M4C_H5_STAGE_ORIGINAL_RESPONSE_BYTES_AND_FULL_HASH');
        $envelope=$response['envelope'];$nested=null;
        if($label==='/api/get_script')m4c_expect($envelope===null&&$capture['business_status']===null,'M4C_H5_STAGE_RAW_TEXT_NOT_JSON');
        else{m4c_expect($raw!==''&&is_array($envelope)&&json_decode($raw,true,128,JSON_THROW_ON_ERROR)===$envelope
                &&($envelope['status']??null)===200&&is_string($envelope['msg']??null)&&$capture['business_status']===200,
                'M4C_H5_STAGE_ORIGINAL_BUSINESS_JSON_ENVELOPE');
            if(in_array($label,['/api/order/computed/:confirmation','/api/order/create/:confirmation'],true)){
                $data=$envelope['data']??null;m4c_expect(is_array($data)&&is_array($data['result']??null),'M4C_H5_STAGE_ACTUAL_NESTED_RESULT');
                $nested=$data['status']??null;m4c_expect($label==='/api/order/create/:confirmation'?$nested==='SUCCESS':in_array($nested,['NONE','SUCCESS'],true),
                    'M4C_H5_STAGE_NORMAL_NESTED_RESULT_STATUS');}
        }
        $projection=$byId[$id];m4c_expect($capture['nested_status']===$nested&&$projection['method']===$wire['method']
            &&$projection['path_template']===$label&&$projection['business_status']===$capture['business_status']
            &&$projection['nested_status']===$nested&&$projection['response_bytes']===$response['body_bytes']
            &&$projection['response_sha256_prefix']===substr($response['body_sha256'],0,12)
            &&$projection['private_capture_sha256_prefix']===substr($capture['private_capture_sha256'],0,12),
            'M4C_H5_STAGE_CAPTURE_MATCHES_ACTUAL_SAFE_PROJECTION');
        m4c_expect($stage!=='B1'||$label==='/api/user','M4C_H5_STAGE_LOGIN_STAGE_ONLY_PRIVATE_USER_RESPONSE');
        if($wire['method']==='POST')m4c_expect(match($stage){
            'B2'=>in_array($label,['/api/cart/add','/api/cart/num','/api/image_base64'],true),
            'B3'=>in_array($label,['/api/order/check_shipping','/api/order/confirm','/api/order/confirmation/:confirmation',
                '/api/order/confirmation/:confirmation/restore','/api/order/computed/:confirmation','/api/image_base64'],true),
            'B4'=>$label==='/api/order/create/:confirmation',default=>false},'M4C_H5_STAGE_NORMAL_WRITE_PHASE');
        $captured[$id]=true;
    }
    if($stage==='B0')m4c_expect($network===[]&&$captures===[],'M4C_H5_STAGE_PRENAVIGATION_EMPTY_OBSERVER_BASELINE');
    elseif($stage==='B1')m4c_expect($login===1&&$captures!==[],'M4C_H5_STAGE_ONE_REAL_LOGIN_PROJECTION_AND_USER_CAPTURE');
    else m4c_expect(count($captured)===count($byId),'M4C_H5_STAGE_ALL_NONAUTH_API_RESPONSES_CAPTURED');
}
function m4ch_stage_captures(array $stages,?string $template=null): array
{
    $all=[];foreach($stages as$stage)foreach($stage['captures']as$capture)
        if($template===null||$capture['path_template']===$template)$all[]=$capture;return $all;
}
function m4ch_stage_data(array $capture): mixed { return $capture['response']['envelope']['data']??null; }
function m4ch_stage_previous(array $source,array $runtime,string $stage): array
{
    $order=['B0','B1','B2','B3','B4'];$position=array_search($stage,$order,true);m4c_expect(is_int($position),'M4C_H5_STAGE_FIXED_ORDER');$prior=[];
    foreach($order as$i=>$name){$path=M4CH_STAGE_CONTROL.'/stage-'.$name.'.json';
        if($i>=$position){m4c_expect(!file_exists($path)&&!is_link($path),'M4C_H5_STAGE_NO_REPEAT_OR_FUTURE_STAGE');continue;}
        $value=m4ch_http_private_read($path);m4ch_stage_keys($value,['schema','scope',...M4CH_STAGE_IDENTITIES,'stage','network','captures',
            'snapshot_base64','snapshot_sha256','business']);m4ch_http_identity($value,$source,$runtime);
        m4c_expect($value['scope']==='MS4_CUSTOMER_H5_PRIVATE_STAGE'&&$value['stage']===$name,'M4C_H5_STAGE_ORIGINAL_PREVIOUS_STAGE');
        m4ch_stage_unserialize($value);$prior[]=$value;
    }return $prior;
}
function m4ch_stage_auxiliary_equal(array $before,array $after): void
{
    m4c_expect($before['auxiliary_rows']===$after['auxiliary_rows'],'M4C_H5_STAGE_ALL_SEVEN_AUXILIARY_TABLES_UNCHANGED');
}
function m4ch_stage_auth_captures(array $stages,int $uid): void
{
    $captures=m4ch_stage_captures($stages,'/api/user');m4c_expect($captures!==[],'M4C_H5_STAGE_REAL_USER_RESPONSE_REQUIRED');
    foreach($captures as$capture){$data=m4ch_stage_data($capture);m4c_expect(is_array($data)&&m4ch_stage_positive($data['uid']??null)===$uid,
        'M4C_H5_STAGE_REAL_AUTHENTICATED_OWNER_RESPONSE');}
}
function m4ch_stage_producer_stats(array $snapshot,array $stages,array $oracle,array $runtime): void
{
    $uid=(int)$oracle['fixture']['user']['uid'];$expected=[];
    foreach(m4ch_stage_captures($stages)as$capture){$label=$capture['path_template'];$request=$capture['request'];
        if($label==='/api/product/detail/:product'){
            $pid=m4ch_stage_positive(basename(parse_url($request['url'],PHP_URL_PATH)));$data=m4ch_stage_data($capture);
            m4c_expect(is_array($data)&&(int)($data['storeInfo']['id']??0)===$pid
                &&(int)($data['mer_id']??0)===(int)$oracle['fixture']['products'][$pid]['mer_id'],
                'M4C_H5_STAGE_ACTUAL_VISITED_PRODUCT_RESPONSE');$expected[]='visit:'.$pid.':1';
        }elseif($label==='/api/cart/add'){
            $body=$request['body'];$pid=m4ch_stage_positive($body['productId']??null,8388607);
            $qty=m4ch_stage_positive($body['cartNum']??null,65535);$expected[]='cart:'.$pid.':'.$qty;
        }
    }
    $actual=[];$keys=['id','type','product_id','uid','visit_num','cart_num','order_num','pay_num','pay_price','cost_price',
        'pay_uid','refund_num','refund_price','collect_num','add_time'];
    foreach($snapshot['facts']['eb_store_product_log']as$row){m4ch_stage_keys($row,$keys);
        m4c_expect(in_array($row['type'],['visit','cart'],true)&&(int)$row['uid']===$uid
            &&m4ch_stage_positive($row['id'],2147483647)>0&&(int)$row['add_time']>=$runtime['not_before']
            &&(int)$row['add_time']<=time(),'M4C_H5_STAGE_REAL_VISIT_CART_STAT_OWNER_AND_TIME');
        $pid=(int)$row['product_id'];m4c_expect(isset($oracle['fixture']['products'][$pid]),'M4C_H5_STAGE_STAT_REAL_PRODUCT');
        foreach(['order_num','pay_num','pay_uid','refund_num','collect_num']as$field)
            m4c_expect((int)$row[$field]===0,'M4C_H5_STAGE_STATS_NO_UNOBSERVED_ACTION');
        foreach(['pay_price','cost_price','refund_price']as$field)m4c_expect($row[$field]==='0.00','M4C_H5_STAGE_STATS_NO_FINANCIAL_EFFECT');
        if($row['type']==='visit'){m4c_expect((int)$row['visit_num']===1&&(int)$row['cart_num']===0,'M4C_H5_STAGE_VISIT_STAT_DEFAULT_COLUMNS');
            $actual[]='visit:'.$pid.':1';}
        else{m4c_expect((int)$row['visit_num']===0&&(int)$row['cart_num']>0,'M4C_H5_STAGE_CART_STAT_DEFAULT_COLUMNS');
            $actual[]='cart:'.$pid.':'.(int)$row['cart_num'];}
    }
    sort($expected,SORT_STRING);sort($actual,SORT_STRING);
    m4c_expect($expected===$actual,'M4C_H5_STAGE_EXACT_ACTUAL_DETAIL_AND_CART_PRODUCER_MULTISET');
}
/** Match all three real cart producers, native full rows, and a complete observed two-store public listing. */
function m4ch_stage_carts(array $snapshot,array $stages,array $oracle,array $runtime): array
{
    $fixture=$oracle['fixture'];$uid=(int)$fixture['user']['uid'];$intent=m4ch_browser_intent($oracle)['ui_intent'];$expected=[];
    foreach(m4ch_stage_captures($stages)as$capture){$label=$capture['path_template'];$body=$capture['request']['body'];
        if($label==='/api/cart/add'){
            $pid=m4ch_stage_positive($body['productId']??null,8388607);$qty=m4ch_stage_positive($body['cartNum']??null,65535);
            $unique=$body['uniqueId']??null;$matches=array_values(array_filter($intent['products'],
                static fn(array$p):bool=>$p['product_id']===$pid&&$p['unique']===$unique));
            m4c_expect(count($matches)===1&&in_array($body['new']??0,[0,'0'],true)&&in_array($body['is_new']??0,[0,'0'],true),
                'M4C_H5_STAGE_OWN_REAL_PERSISTENT_CART_PRODUCER');
            foreach(['combinationId','secKillId','bargainId','advanceId','pinkId','virtual_type']as$field)
                m4c_expect(in_array($body[$field]??0,[0,'0'],true),'M4C_H5_STAGE_CART_NO_DISABLED_ACTIVITY');
            $data=m4ch_stage_data($capture);m4c_expect(is_array($data),'M4C_H5_STAGE_ACTUAL_CART_ADD_RESPONSE');$ref=m4ch_stage_ref($data['cartId']??null);
            if(isset($expected[$ref]))m4c_expect($expected[$ref]['product_id']===$pid&&$expected[$ref]['unique']===$unique,
                'M4C_H5_STAGE_SAME_CART_REF_SAME_REAL_SKU');
            $expected[$ref]=['ref'=>$ref,'product_id'=>$pid,'unique'=>$unique,'sku_id'=>$matches[0]['sku_id'],
                'qty'=>($expected[$ref]['qty']??0)+$qty,'mer_id'=>$matches[0]['mer_id']];
        }elseif($label==='/api/cart/num'){
            $ref=m4ch_stage_ref($body['id']??null);m4c_expect(isset($expected[$ref]),'M4C_H5_STAGE_CART_NUMBER_FROM_REAL_ADD');
            $expected[$ref]['qty']=m4ch_stage_positive($body['number']??null,65535);
        }
    }
    m4c_expect(count($expected)===3&&count($snapshot['facts']['eb_store_cart'])===3,'M4C_H5_STAGE_EXACT_THREE_NATIVE_CART_LINES');
    $seen=[];$byProduct=[];$cartKeys=['id','uid','type','product_id','product_attr_unique','cart_num','add_time','is_pay','is_del','is_new',
        'combination_id','seckill_id','bargain_id','advance_id','status'];
    foreach($snapshot['facts']['eb_store_cart']as$row){m4ch_stage_keys($row,$cartKeys);$ref=m4ch_stage_ref($row['id']);$e=$expected[$ref]??null;
        m4c_expect(is_array($e)&&!isset($seen[$ref])&&(int)$row['uid']===$uid&&(int)$row['product_id']===$e['product_id']
            &&$row['product_attr_unique']===$e['unique']&&(int)$row['cart_num']===$e['qty']&&(int)$row['type']===0
            &&(int)$row['status']===1&&(int)$row['add_time']>=$runtime['not_before']&&(int)$row['add_time']<=time(),
            'M4C_H5_STAGE_COMPLETE_REAL_NATIVE_CART_SELECTION');
        foreach(['is_pay','is_del','is_new','combination_id','seckill_id','bargain_id','advance_id']as$field)
            m4c_expect((int)$row[$field]===0,'M4C_H5_STAGE_NATIVE_CART_DEFAULT_FLAGS');
        $product=$fixture['products'][$e['product_id']];$sku=$fixture['skus'][$e['sku_id']];
        m4c_expect((int)$product['mer_id']===$e['mer_id']&&(int)$sku['product_id']===$e['product_id']&&$sku['unique']===$e['unique']
            &&!isset($byProduct[$e['product_id']]),'M4C_H5_STAGE_CART_REAL_ROOT_SKU_MERCHANT_JOIN');
        $seen[$ref]=true;$byProduct[$e['product_id']]=$e;
    }
    foreach($intent['products']as$product)m4c_expect(isset($byProduct[$product['product_id']])
        &&$byProduct[$product['product_id']]['qty']===$product['qty']&&$byProduct[$product['product_id']]['sku_id']===$product['sku_id'],
        'M4C_H5_STAGE_A_TWO_B_ONE_C_ONE_REAL_QUANTITIES');
    $complete=0;
    foreach(m4ch_stage_captures($stages,'/api/cart/list')as$capture){$data=m4ch_stage_data($capture);
        if(!is_array($data)||count($data['valid']??[])!==3||count($data['groups']??[])!==2)continue;
        m4c_expect(($data['invalid']??null)===[],'M4C_H5_STAGE_NO_INVALID_EXPECTED_CART');$refs=[];
        foreach($data['valid']as$row){$ref=m4ch_stage_ref($row['id']??null);$e=$expected[$ref]??null;
            m4c_expect(is_array($e)&&!isset($refs[$ref])&&(int)($row['cart_num']??0)===$e['qty']
                &&(int)($row['productInfo']['id']??0)===$e['product_id']&&(int)($row['productInfo']['mer_id']??0)===$e['mer_id']
                &&($row['productInfo']['attrInfo']['unique']??null)===$e['unique'],'M4C_H5_STAGE_PUBLIC_CART_MATCHES_NATIVE_ROWS');$refs[$ref]=true;}
        $groupRefs=[];$merchants=[];
        foreach($data['groups']as$group){$mer=(int)($group['mer_id']??0);m4c_expect(in_array($mer,[(int)$fixture['merchant']['mer_id'],
                (int)$oracle['second_store']['merchant']['mer_id']],true)&&!isset($merchants[$mer])
                &&(int)($group['store']['mer_id']??0)===$mer&&($group['store']['name']??null)===$fixture['merchants'][$mer]['name']
                &&is_array($group['cart_refs']??null)&&is_array($group['rows']??null),'M4C_H5_STAGE_TWO_REAL_PUBLIC_STORE_GROUPS');
            $rowRefs=[];foreach($group['rows']as$row){$ref=m4ch_stage_ref($row['id']??null);
                m4c_expect(isset($expected[$ref])&&$expected[$ref]['mer_id']===$mer&&!isset($rowRefs[$ref]),
                    'M4C_H5_STAGE_PUBLIC_GROUP_ROW_REAL_OWNER');$rowRefs[$ref]=true;}
            $declared=[];foreach($group['cart_refs']as$value){$ref=m4ch_stage_ref($value);m4c_expect(isset($rowRefs[$ref])&&!isset($declared[$ref])
                    &&!isset($groupRefs[$ref]),'M4C_H5_STAGE_PUBLIC_GROUP_EXACT_REFERENCE_PARTITION');$declared[$ref]=true;$groupRefs[$ref]=true;}
            m4c_expect(count($declared)===count($rowRefs),'M4C_H5_STAGE_PUBLIC_GROUP_COMPLETE_ROWS');$merchants[$mer]=true;
        }
        m4c_expect(count($refs)===3&&count($groupRefs)===3,'M4C_H5_STAGE_FULL_TWO_STORE_CART_LIST_CAPTURE');++$complete;
    }
    m4c_expect($complete>0,'M4C_H5_STAGE_COMPLETE_THREE_LINE_PUBLIC_CART_OBSERVED');$countObserved=false;
    foreach(m4ch_stage_captures($stages,'/api/cart/count')as$capture){$data=m4ch_stage_data($capture);
        if(!is_array($data)||(int)($data['count']??-1)!==4||!is_array($data['ids']??null))continue;
        $refs=array_map('m4ch_stage_ref',$data['ids']);$wanted=array_map('strval',array_keys($expected));sort($refs,SORT_STRING);sort($wanted,SORT_STRING);
        if($refs===$wanted)$countObserved=true;
    }
    m4c_expect($countObserved,'M4C_H5_STAGE_REAL_CART_COUNT_FOUR_UNITS_THREE_IDS');return $byProduct;
}
function m4ch_stage_default_pdo(PDO $reader): PDO
{
    $connection=Db::connect();$default=$connection->getPdo();
    if(!$default instanceof PDO){$connection->query('SELECT 1',[],true);$default=$connection->getPdo();}
    m4c_expect($default instanceof PDO&&$connection===Db::connect()&&$default===Db::connect()->getPdo()
        &&$reader!==$default&&!$reader->inTransaction()&&!$default->inTransaction(),
        'M4C_H5_STAGE_ACTUAL_DEFAULT_AND_INDEPENDENT_NATIVE_PDOS');return $default;
}
function m4ch_stage_default_stable(PDO $reader,PDO $default): void
{
    m4c_expect(Db::connect()->getPdo()===$default&&$reader!==$default&&!$reader->inTransaction()&&!$default->inTransaction(),
        'M4C_H5_STAGE_SAME_ACTUAL_DEFAULT_PDO_RETAINED');
}
function m4ch_stage_app_attempt(array $source,array $runtime,array $request): void
{
    $stage=$request['stage'];$mode=match($stage){'B3'=>'prepare','B4'=>'verify',default=>null};
    m4c_expect(is_string($mode)&&empty($GLOBALS['m4c_app_started']),'M4C_H5_STAGE_ONE_FRESH_REAL_APP_PROCESS');
    $attempt=m4ch_http_header($request,'MS4_CUSTOMER_H5_APP_ATTEMPT')+['stage'=>$stage,'mode'=>$mode,
        'runtime_path'=>'/tmp/ms4-customer-http-'.$source['run_id'].'-'.$mode.'/'];
    m4ch_http_publish(M4CH_STAGE_CONTROL.'/stage-'.$stage.'-attempt.json',$attempt);
    m4c_app($mode,$source);
}
/** Pure checks of the actual complete formatter DTO; never calls a producer or reads/writes a frame. */
function m4ch_stage_confirmation_dto(array $data,array $oracle,array $frame,string $key,int $addressId): void
{
    $fixture=$oracle['fixture'];$uid=(int)$fixture['user']['uid'];$mer=(int)$fixture['merchant']['mer_id'];
    m4ch_stage_keys($data,['storeFreePostage','valid_count','virtual_type','deduction','addressInfo','seckill_id','combination_id',
        'bargain_id','advance_id','cartInfo','custom_form','priceGroup','orderKey','marketplace_confirmation_version','mer_id',
        'source_kind','store','marketplace_create_enabled','offlinePostage','userInfo','integralRatio','offline_pay_status',
        'yue_pay_status','pay_weixin_open','friend_pay_status','store_self_mention','ali_pay_status','system_store','invoice_func',
        'special_invoice','usable_integral','integral_open']);
    m4c_expect($data['orderKey']===$key&&$data['marketplace_confirmation_version']===1&&$data['mer_id']===$mer
        &&$data['source_kind']==='persistent'&&$data['marketplace_create_enabled']===true&&$data['valid_count']===2
        &&$data['virtual_type']===false&&$data['deduction']===false,'M4C_H5_STAGE_FULL_V1_RESTORE_DTO');
    foreach(['seckill_id','combination_id','bargain_id','advance_id']as$field)
        m4c_expect($data[$field]===0,'M4C_H5_STAGE_FULL_NORMAL_CONFIRMATION_ACTIVITY_FIELDS');
    $store=['mer_id'=>$mer];foreach(['name','logo','after_sale_contact','shipping_note']as$field)$store[$field]=$fixture['merchant'][$field];
    m4c_expect(is_array($data['store'])&&m4ch_http_same($data['store'],$store)
        &&is_array($data['userInfo'])&&m4ch_stage_positive($data['userInfo']['uid']??null)===$uid
        &&($data['userInfo']['vip']??null)===false&&!array_key_exists('pwd',$data['userInfo']),
        'M4C_H5_STAGE_FULL_CONFIRMATION_ACTUAL_STORE_AND_USER');
    foreach(['now_money','integral']as$field)m4c_expect(isset($data['userInfo'][$field])
        &&(string)$data['userInfo'][$field]===(string)$fixture['user'][$field],'M4C_H5_STAGE_REAL_CONFIRMATION_USER_BALANCES');
    m4c_expect($data['pay_weixin_open']===false&&$data['ali_pay_status']===false&&$data['yue_pay_status']===2
        &&$data['offline_pay_status']===1&&$data['friend_pay_status']===0&&$data['store_self_mention']===0
        &&$data['integral_open']===false&&$data['invoice_func']===true&&$data['special_invoice']===false
        &&$data['system_store']===[]&&$data['usable_integral']==='0','M4C_H5_STAGE_FULL_CURRENT_NORMAL_CAPABILITIES');
    $address=$fixture['address'];unset($address['add_time'],$address['is_del']);
    m4c_expect(($addressId===0&&$data['addressInfo']===[])||($addressId===(int)$fixture['address']['id']
        &&is_array($data['addressInfo'])&&m4ch_http_same($data['addressInfo'],$address)),
        'M4C_H5_STAGE_FULL_DTO_ACTUAL_OWN_ADDRESS_OR_NONE');
    m4c_expect(is_array($data['cartInfo'])&&array_is_list($data['cartInfo'])&&count($data['cartInfo'])===2
        &&is_array($data['custom_form'])&&m4ch_http_same($data['custom_form'],$frame['custom_form'])
        &&is_array($data['priceGroup']),'M4C_H5_STAGE_COMPLETE_CART_FORMS_AND_PRICE_DTO');
    $refs=[];foreach($data['cartInfo']as$i=>$row){m4c_expect(is_array($row)&&is_string($row['id']??null)
            &&is_array($row['productInfo']??null)&&is_array($row['productInfo']['attrInfo']??null),'M4C_H5_STAGE_FULL_PUBLIC_CART_SKU_DTO');
        $line=$frame['selection'][$i]??null;$product=$row['productInfo'];$sku=$product['attrInfo'];
        m4c_expect(is_array($line)&&$row['id']===$line['cart_ref']&&(int)($row['product_id']??0)===$line['product_id']
            &&(int)($row['type']??-1)===0&&(int)($row['cart_num']??0)===$line['qty']
            &&($row['product_attr_unique']??null)===$line['unique']&&(int)($product['id']??0)===$line['product_id']
            &&($product['mer_id']??null)===$mer&&($product['store_name']??null)===$fixture['products'][$line['product_id']]['store_name']
            &&(int)($sku['id']??0)===$line['sku_id']&&($sku['unique']??null)===$line['unique']
            &&($sku['suk']??null)===$fixture['skus'][$line['sku_id']]['suk'],'M4C_H5_STAGE_DTO_EXACT_ORIGINAL_STRING_REF_PRODUCT_SKU_QUANTITY');
        $refs[]=$row['id'];}
    m4c_expect($refs===$frame['cart_refs'],'M4C_H5_STAGE_DTO_COMPLETE_ORIGINAL_CART_REF_ORDER');
    $price=$data['priceGroup'];m4ch_stage_keys($price,['storePostage','storeFreePostage','isStoreFreePostage','sumPrice','totalPrice',
        'vipPrice','storePostageDiscount','cartInfo','levelPrice','memberPrice','giftPrice']);
    m4c_expect(is_bool($price['isStoreFreePostage'])&&m4ch_http_same($price['cartInfo'],$data['cartInfo'])
        &&$data['storeFreePostage']===$price['storeFreePostage'],'M4C_H5_STAGE_COMPLETE_PRICE_GROUP_CART_AND_THRESHOLD');
    foreach(['storePostage','storeFreePostage','sumPrice','totalPrice','vipPrice','storePostageDiscount','levelPrice','memberPrice','giftPrice']as$field)
        m4c_expect(is_string($price[$field])&&preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}\z/D',$price[$field])===1,
            'M4C_H5_STAGE_FULL_PRICE_GROUP_EXACT_DECIMAL_STRINGS');
    foreach(['totalPrice'=>'70.55','sumPrice'=>'70.55','vipPrice'=>'0.00','levelPrice'=>'0.00','memberPrice'=>'0.00','giftPrice'=>'0.00']as$field=>$expected)
        m4cb_public_money($price,$field,$expected);
}
/** Same fresh key and real readonly previews. Complete private bodies stay in the original stage captures. */
function m4ch_stage_restore_chain(array $stages,array $oracle,array $frame,string $key): void
{
    $fresh=m4ch_stage_captures($stages,'/api/order/confirm');
    m4c_expect(count($fresh)===1,'M4C_H5_STAGE_EXACTLY_ONE_FRESH_CONFIRMATION_NO_REBUILD');
    $original=m4ch_stage_data($fresh[0]);m4c_expect(is_array($original),'M4C_H5_STAGE_FULL_ORIGINAL_CONFIRMATION_DTO');
    $addressId=(int)($original['addressInfo']['id']??0);m4ch_stage_confirmation_dto($original,$oracle,$frame,$key,$addressId);
    $lookups=m4ch_stage_captures($stages,'/api/order/confirmation/:confirmation');
    $restores=m4ch_stage_captures($stages,'/api/order/confirmation/:confirmation/restore');
    m4c_expect($lookups!==[]&&$restores!==[],'M4C_H5_STAGE_REAL_FRESH_REDIRECT_LOOKUP_AND_RESTORE_REQUIRED');
    foreach($lookups as$capture){$data=m4ch_stage_data($capture);m4c_expect(is_array($data),'M4C_H5_STAGE_REAL_LOOKUP_DTO');
        m4ch_stage_keys($data,['state','key']);m4cb_lookup_result($data,$key,null);
        $path=parse_url($capture['request']['url'],PHP_URL_PATH);m4c_expect(is_string($path)
            &&preg_match('~\A/api/order/confirmation/([^/]+)\z~D',$path,$match)===1&&rawurldecode($match[1])===$key,
            'M4C_H5_STAGE_LOOKUP_ORIGINAL_FRESH_ROUTE_KEY');}
    $resolved=[];
    foreach($restores as$capture){$result=m4ch_stage_data($capture);$body=$capture['request']['body'];
        m4c_expect(is_array($result)&&is_array($body),'M4C_H5_STAGE_REAL_RESTORE_RESPONSE_AND_HINTS');
        m4ch_stage_keys($result,['state','key','confirmation','selection']);m4cb_lookup_result($result,$key,null);
        $path=parse_url($capture['request']['url'],PHP_URL_PATH);m4c_expect(is_string($path)
            &&preg_match('~\A/api/order/confirmation/([^/]+)/restore\z~D',$path,$match)===1&&rawurldecode($match[1])===$key,
            'M4C_H5_STAGE_RESTORE_ORIGINAL_FRESH_ROUTE_KEY');
        m4ch_stage_keys($body,['addressId','invoice_id','payType']);m4c_expect($body['payType']==='',
            'M4C_H5_STAGE_RESTORE_ACTUAL_NORMAL_PAYMENT_INTENT');
        $selection=$result['selection'];m4c_expect(is_array($selection),'M4C_H5_STAGE_COMPLETE_ACTUAL_RESTORE_SELECTION');
        m4ch_stage_keys($selection,['address','invoice']);
        foreach(['address'=>'addressId','invoice'=>'invoice_id']as$kind=>$field){$hint=$body[$field];
            m4c_expect((is_int($hint)||is_string($hint))&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$hint)===1,
                'M4C_H5_STAGE_ACTUAL_RESTORE_HINT_NATIVE_TYPE');$hint=(int)$hint;$owned=(int)$oracle['fixture'][$kind]['id'];
            m4c_expect($hint===0||$hint===$owned,'M4C_H5_STAGE_ACTUAL_OWN_RESTORE_HINT_OR_NONE');$row=$selection[$kind];
            m4c_expect(is_array($row),'M4C_H5_STAGE_ACTUAL_RESTORE_SELECTION_ROW');
            m4ch_stage_keys($row,$kind==='address'?['mode','id','status']:['mode','id','status','invoice']);
            m4c_expect($row['id']===$hint&&$row['mode']===($hint===0?'none':'id')
                &&$row['status']===($hint===0?'none':'resolved'),'M4C_H5_STAGE_NORMAL_OWN_HINT_RESOLVED_WITHOUT_DEFAULT_SUBSTITUTION');
            if($kind==='invoice'){
                $expectedInvoice=$oracle['fixture']['invoice'];
                $expectedInvoice['add_time']=empty($expectedInvoice['add_time'])?'':(new \DateTimeImmutable('@'.m4ch_stage_positive($expectedInvoice['add_time'])))
                    ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
                m4c_expect($hint===0?$row['invoice']===[]:is_array($row['invoice'])
                    &&m4ch_http_same($row['invoice'],$expectedInvoice),'M4C_H5_STAGE_RESTORE_COMPLETE_OWN_INVOICE_OR_NONE');
            }
        }
        $data=$result['confirmation'];m4c_expect(is_array($data),'M4C_H5_STAGE_COMPLETE_RESTORED_CONFIRMATION');
        m4ch_stage_confirmation_dto($data,$oracle,$frame,$key,(int)$body['addressId']);
        $stable=$data;$before=$original;foreach(['addressInfo','priceGroup','userInfo']as$field){unset($stable[$field],$before[$field]);}
        m4c_expect(m4ch_http_same($stable,$before)&&m4ch_http_same($data['cartInfo'],$original['cartInfo']),
            'M4C_H5_STAGE_RESTORE_COMPLETE_ORIGINAL_FORMS_CAPABILITIES_AND_CART_DTO');
        $user=$data['userInfo'];$oldUser=$original['userInfo'];m4ch_stage_keys($user,array_keys($oldUser));
        unset($user['last_time'],$oldUser['last_time']);
        m4c_expect(m4ch_http_same($user,$oldUser),'M4C_H5_STAGE_RESTORE_COMPLETE_USER_AUTH_TIME_ONLY');
        if((int)$body['addressId']===(int)$oracle['fixture']['address']['id'])$resolved[]=$data;
    }
    m4c_expect($resolved!==[],'M4C_H5_STAGE_REAL_RESTORE_OWN_ADDRESS_PREVIEW_REQUIRED');
    $computed=m4ch_stage_captures($stages,'/api/order/computed/:confirmation');
    m4c_expect($computed!==[],'M4C_H5_STAGE_REAL_RESTORED_COMPUTED_REQUIRED');$last=m4ch_stage_data($computed[count($computed)-1]);
    m4c_expect(is_array($last)&&($last['status']??null)==='NONE'&&is_array($last['result']??null),
        'M4C_H5_STAGE_RESTORE_FINAL_REAL_COMPUTED_NONE');$price=$last['result'];
    m4ch_stage_keys($price,['total_price','gift_price','pay_price','pay_postage','coupon_price','deduction_price','usedIntegral',
        'SurplusIntegral','storePostageDiscount','isStoreFreePostage','storeFreePostage','marketplace_confirmation_version','mer_id',
        'source_kind','store','marketplace_create_enabled']);
    foreach(['total_price'=>'70.55','gift_price'=>'0.00','pay_price'=>'78.56','pay_postage'=>'8.01','coupon_price'=>'0.00',
        'deduction_price'=>'0.00','storePostageDiscount'=>'0.00']as$field=>$expected)m4cb_public_money($price,$field,$expected);
    $view=$resolved[count($resolved)-1];m4c_expect($price['usedIntegral']===0&&$price['SurplusIntegral']===0
        &&$price['isStoreFreePostage']===$view['priceGroup']['isStoreFreePostage']
        &&$price['storeFreePostage']===$view['priceGroup']['storeFreePostage']&&$price['marketplace_confirmation_version']===1
        &&$price['marketplace_create_enabled']===true&&$price['mer_id']===$view['mer_id']&&$price['source_kind']===$view['source_kind']
        &&m4ch_http_same($price['store'],$view['store'])&&$price['pay_postage']===$view['priceGroup']['storePostage'],
        'M4C_H5_STAGE_RESTORED_DTO_SELECTION_AND_FINAL_REAL_QUOTE_BOUND');
}
function m4ch_stage_frame(array $stages,array $source,array $oracle,array $runtime,array $byProduct): array
{
    $fixture=$oracle['fixture'];$uid=(int)$fixture['user']['uid'];$mer=(int)$fixture['merchant']['mer_id'];
    $confirmations=m4ch_stage_captures($stages,'/api/order/confirm');m4c_expect($confirmations!==[],'M4C_H5_STAGE_REAL_CONFIRM_RESPONSE_REQUIRED');
    $key=null;$confirmData=null;$refs=[];
    foreach($fixture['selected']as$line)$refs[]=$byProduct[$line['product_id']]['ref'];$refs=m4ch_stage_refs(implode(',',$refs));
    foreach($confirmations as$capture){$data=m4ch_stage_data($capture);$body=$capture['request']['body'];
        m4c_expect(is_array($data)&&($data['marketplace_confirmation_version']??null)===1
            &&($data['marketplace_create_enabled']??null)===true&&($data['mer_id']??null)===$mer
            &&($data['source_kind']??null)==='persistent'&&is_string($data['orderKey']??null)
            &&m4ch_stage_refs($body['cartId']??'')===$refs&&(int)($body['mer_id']??0)===$mer
            &&in_array($body['new']??0,[0,'0'],true)&&(int)($body['shipping_type']??1)===1,
            'M4C_H5_STAGE_ACTUAL_SINGLE_STORE_CONFIRMATION');
        $actual=MarketplaceOrderInput::confirmationKey($data['orderKey']);
        m4c_expect($key===null||$actual===$key,'M4C_H5_STAGE_ONE_ORIGINAL_CONFIRMATION_KEY_NO_REBUILD');$key=$actual;$confirmData=$data;
        m4cb_public_money($data['priceGroup'],'totalPrice','70.55');
    }
    $computed=m4ch_stage_captures($stages,'/api/order/computed/:confirmation');m4c_expect($computed!==[],'M4C_H5_STAGE_ACTUAL_COMPUTED_BEFORE_CREATE');
    $last=$computed[count($computed)-1];$body=$last['request']['body'];$data=m4ch_stage_data($last);
    $routeKey=rawurldecode(basename(parse_url($last['request']['url'],PHP_URL_PATH)));
    m4c_expect($routeKey===$key&&($data['status']??null)==='NONE'&&is_array($data['result']??null)
        &&($data['result']['marketplace_create_enabled']??null)===true&&($data['result']['marketplace_confirmation_version']??null)===1
        &&($data['result']['mer_id']??null)===$mer&&($data['result']['source_kind']??null)==='persistent'
        &&(int)($body['addressId']??0)===(int)$fixture['address']['id']
        &&(int)($body['mer_id']??0)===$mer&&($body['payType']??null)===''&&(int)($body['shipping_type']??0)===1
        &&in_array($body['useIntegral']??0,[0,'0',false],true)&&(int)($body['couponId']??0)===0,
        'M4C_H5_STAGE_LAST_REAL_COMPUTED_OWN_ADDRESS_AND_ORIGINAL_KEY');
    m4cb_public_money($data['result'],'pay_price','78.56');m4cb_public_money($data['result'],'pay_postage','8.01');
    $cached=\crmeb\services\CacheService::get('user_order_'.$uid.$key);
    m4c_expect(is_array($cached),'M4C_H5_STAGE_ACTUAL_UNEXPIRED_OWNER_CONFIRMATION_CACHE');
    $frame=\app\services\marketplace\order\MarketplaceCartSelection::frame($cached,$uid);
    m4c_expect($frame['mer_id']===$mer&&$frame['source_kind']==='persistent'&&$frame['cart_refs']===$refs
        &&count($frame['selection'])===2&&count($frame['custom_form'])===2&&$frame['custom_form']===$confirmData['custom_form'],
        'M4C_H5_STAGE_PRIVATE_FRAME_MATCHES_ACTUAL_PUBLIC_CONFIRMATION');
    foreach($frame['selection']as$line){$expected=$byProduct[$line['product_id']]??null;
        m4c_expect(is_array($expected)&&$expected['mer_id']===$mer&&$line['cart_ref']===$expected['ref']
            &&$line['sku_id']===$expected['sku_id']&&$line['unique']===$expected['unique']&&$line['sku_type']===0
            &&$line['qty']===$expected['qty'],'M4C_H5_STAGE_ORIGINAL_FRAME_REAL_CART_SKU_SELECTION');}
    foreach($frame['custom_form']as$definition){$product=$fixture['products'][$definition['product_id']]??null;
        m4c_expect(is_array($product)&&$definition['product_name']===$product['store_name'],'M4C_H5_STAGE_FRAME_FORM_REAL_PRODUCT');
        $raw=json_decode($product['custom_form'],true,32,JSON_THROW_ON_ERROR);$fields=[];
        foreach($raw as$i=>$field)$fields[]=['field_index'=>$i]+$field;
        m4c_expect($definition['fields']===$fields&&$definition['definition_sha256']===hash('sha256',MarketplaceOrderInput::canonical([
            'product_id'=>$definition['product_id'],'fields'=>$fields])),'M4C_H5_STAGE_FULL_ORIGINAL_FORM_DEFINITION_HASH');
    }
    m4ch_stage_restore_chain($stages,$oracle,$frame,$key);
    $ui=0;$entries=0;m4ch_http_directory(M4CH_STAGE_CONTROL.'/ui-captures');
    foreach(new DirectoryIterator(M4CH_STAGE_CONTROL.'/ui-captures')as$file){if($file->isDot())continue;
        m4c_expect(++$entries<=4096&&$file->isFile()&&!$file->isLink()
            &&preg_match('/\A[a-f0-9]{48}\.json\z/D',$file->getFilename())===1,'M4C_H5_STAGE_FINITE_ORIGINAL_UI_CAPTURES');
        $capture=m4ch_http_private_read(M4CH_STAGE_CONTROL.'/ui-captures/'.$file->getFilename());
        if(($capture['scene']??null)!=='confirm-filled')continue;
        m4ch_http_identity($capture,$source,$runtime);m4ch_http_ui_capture($capture,$source,$runtime);
        m4c_expect(m4ch_http_confirmation_url($capture['dom']['url'],$source)===$key,'M4C_H5_STAGE_REAL_CAPTURE_ORIGINAL_CONFIRMATION_KEY');
        $actual=[];foreach($stages as$stage)foreach($stage['network']as$row)$actual[$row['request_id']]=$row['projection'];
        foreach($capture['network']as$row)m4c_expect(isset($actual[$row['request_id']])&&m4ch_http_same($actual[$row['request_id']],$row['projection']),
            'M4C_H5_STAGE_CONFIRM_CAPTURE_REAL_OBSERVED_NETWORK');++$ui;
    }
    m4c_expect($ui>0,'M4C_H5_STAGE_ORIGINAL_CONFIRM_CAPTURE_BEFORE_B3_NO_FUTURE_DEPENDENCY');
    $addresses=m4ch_stage_captures($stages,'/api/address/detail/:address');$addressObserved=false;
    foreach($addresses as$capture){$row=m4ch_stage_data($capture);if(!is_array($row)||(int)($row['id']??0)!==(int)$fixture['address']['id'])continue;
        foreach(['real_name','phone','province','city','district','detail']as$field)m4c_expect(($row[$field]??null)===$fixture['address'][$field],
            'M4C_H5_STAGE_ACTUAL_OWNED_ADDRESS_RESPONSE');$addressObserved=true;}
    m4c_expect($addressObserved,'M4C_H5_STAGE_REAL_UI_OWN_ADDRESS_SELECTED');$invoiceObserved=false;
    foreach(m4ch_stage_captures($stages,'/api/v2/invoice')as$capture){$rows=m4ch_stage_data($capture);m4c_expect(is_array($rows)&&array_is_list($rows),
            'M4C_H5_STAGE_ACTUAL_INVOICE_LIST');foreach($rows as$row)if((int)($row['id']??0)===(int)$fixture['invoice']['id']){
                foreach(['name','header_type','type']as$field)m4c_expect((string)($row[$field]??'')===(string)$fixture['invoice'][$field],
                    'M4C_H5_STAGE_ACTUAL_OWNED_INVOICE_HEADING');$invoiceObserved=true;}}
    m4c_expect($invoiceObserved,'M4C_H5_STAGE_REAL_UI_OWN_INVOICE_AVAILABLE');return ['key'=>$key,'frame'=>$frame];
}
function m4ch_stage_answers(array $request,array $frame,array $oracle): void
{
    $answers=$request['options']['custom_form'];$intent=$oracle['ui_intent'];$definitions=$frame['custom_form'];
    m4c_expect(count($answers)===2&&count($intent['custom_form_inputs'])===2,'M4C_H5_STAGE_TWO_REAL_NONEMPTY_ANSWER_GROUPS');
    foreach($definitions as$i=>$definition){$answer=$answers[$i]??null;$target=$intent['custom_form_inputs'][$i]??null;
        m4c_expect(is_array($answer)&&is_array($target)&&$answer['product_id']===$definition['product_id']
            &&$target['product_id']===$definition['product_id']&&$answer['definition_sha256']===$definition['definition_sha256']
            &&count($answer['values'])===count($definition['fields'])&&count($target['fields'])===count($definition['fields']),
            'M4C_H5_STAGE_ANSWERS_BOUND_TO_ORIGINAL_PRODUCT_HASH');
        foreach($definition['fields']as$j=>$field)m4c_expect(($answer['values'][$j]['field_index']??null)===$field['field_index']
            &&($target['fields'][$j]['field_index']??null)===$field['field_index']&&($target['fields'][$j]['label']??null)===$field['label']
            &&($answer['values'][$j]['value']??null)===$target['fields'][$j]['value'],
            'M4C_H5_STAGE_REAL_UI_ANSWER_TEXT_AND_STRING_ZERO_PRESERVED');
    }
    m4c_expect($request['options']['mark']===$intent['mark']&&$request['options']['invoice_id']===$intent['invoice_id']
        &&$request['address_id']===$intent['address_id']&&$request['options']['payType']==='',
        'M4C_H5_STAGE_REAL_SUBMITTED_MARK_ADDRESS_INVOICE_UNPAID_INTENT');
}
function m4ch_stage_detail(array $stages,array $frame,array $input,array $oracle,string $number): void
{
    $fixture=$oracle['fixture'];$details=m4ch_stage_captures($stages,'/api/order/detail/:order');
    m4c_expect($details!==[],'M4C_H5_STAGE_REAL_SAVED_ORDER_DETAIL_CAPTURE_REQUIRED');
    $expectedForms=$frame['custom_form'];foreach($expectedForms as$i=>&$group){unset($group['cart_refs']);
        foreach($group['fields']as$j=>&$field)$field['value']=$input['request']['options']['custom_form'][$i]['values'][$j]['value'];unset($field);}unset($group);
    foreach($details as$capture){$data=m4ch_stage_data($capture);
        m4c_expect(rawurldecode(basename(parse_url($capture['request']['url'],PHP_URL_PATH)))===$number&&is_array($data)
            &&($data['order_id']??null)===$number&&($data['marketplace_trade_version']??null)===1&&(int)($data['paid']??-1)===0
            &&(int)($data['mer_id']??0)===(int)$fixture['merchant']['mer_id']&&($data['custom_form']??null)===$expectedForms
            &&($data['merchant_name_snapshot']??null)===$fixture['merchant']['name'],
            'M4C_H5_STAGE_ACTUAL_UNPAID_DETAIL_STORE_AND_SAVED_FORMS');
        foreach(['total_price'=>'70.55','pay_price'=>'78.56','pay_postage'=>'8.01']as$field=>$value)m4cb_public_money($data,$field,$value);
        m4c_expect(($data['real_name']??null)===$fixture['address']['real_name']&&($data['user_phone']??null)===$fixture['address']['phone']
            &&($data['user_address']??null)===$fixture['address']['province'].' '.$fixture['address']['city'].' '.$fixture['address']['district'].' '.$fixture['address']['detail']
            &&(int)($data['invoice']['invoice_id']??0)===(int)$fixture['invoice']['id'],'M4C_H5_STAGE_ACTUAL_SAVED_ADDRESS_INVOICE_DETAIL');
        $carts=$data['cartInfo']??null;m4c_expect(is_array($carts)&&array_is_list($carts)&&count($carts)===2,'M4C_H5_STAGE_DETAIL_EXACT_TWO_SAVED_ITEMS');$seen=[];
        foreach($carts as$row){$ref=m4ch_stage_ref($row['id']??null);$selected=array_values(array_filter($frame['selection'],
                static fn(array$line):bool=>$line['cart_ref']===$ref));m4c_expect(count($selected)===1&&!isset($seen[$ref]),'M4C_H5_STAGE_DETAIL_ORIGINAL_CART_REFERENCE');
            $line=$selected[0];m4c_expect((int)($row['cart_num']??0)===$line['qty']&&(int)($row['productInfo']['id']??0)===$line['product_id']
                &&(int)($row['productInfo']['mer_id']??0)===(int)$fixture['merchant']['mer_id']
                &&($row['productInfo']['store_name']??null)===$fixture['products'][$line['product_id']]['store_name']
                &&($row['productInfo']['attrInfo']['unique']??null)===$fixture['skus'][$line['sku_id']]['unique'],
                'M4C_H5_STAGE_DETAIL_REAL_SELECTED_SKU_QUANTITY');$seen[$ref]=true;}
    }
}
/** One finite call per B0..B4. No HTTP request, business writer or invented authenticated context. */
function m4ch_stage(array $source,array $runtime,array $request): array
{
    m4ch_stage_keys($request,['schema','scope',...M4CH_STAGE_IDENTITIES,'stage','network','captures']);
    m4ch_http_identity($request,$source,$runtime);
    m4c_expect($request['scope']==='MS4_CUSTOMER_H5_STAGE_REQUEST'&&in_array($request['stage'],['B0','B1','B2','B3','B4'],true),
        'M4C_H5_STAGE_FINITE_REQUEST');$stage=$request['stage'];m4cb_phase('business_h5_stage_'.strtolower($stage));
    $loaded=m4ch_stage_sealed($source);$oracle=$loaded['oracle'];$baseline=$loaded['baseline'];
    $prior=m4ch_stage_previous($source,$runtime,$stage);m4ch_stage_network($request,$source,$oracle,$prior);
    $all=[...$prior,$request];$uid=(int)$oracle['fixture']['user']['uid'];$reader=audit_db();
    m4c_expect(!$reader->inTransaction()&&empty($GLOBALS['m4c_app_started']),'M4C_H5_STAGE_NO_APP_BEFORE_NATIVE_SNAPSHOT');
    $pre=m4ch_stage_snapshot($reader);$previous=$prior===[]?$baseline:m4ch_stage_unserialize($prior[count($prior)-1]);
    m4ch_stage_auxiliary_equal($baseline,$pre);m4ch_storefront_advance_equal($reader,$oracle);$business=[];$snapshot=$pre;
    if($stage==='B0'){
        m4c_expect($pre===$baseline,'M4C_H5_STAGE_B0_ALL_PREPARED_NATIVE_ROWS_EQUAL');
        $users=array_values(array_filter($pre['facts']['eb_user'],static fn(array$row):bool=>(int)$row['uid']===$uid));
        m4c_expect(count($users)===1,'M4C_H5_STAGE_B0_ONE_ORIGINAL_CUSTOMER');m4cb_equal_row($users[0],$oracle['fixture']['user']);
        foreach(['eb_store_cart','eb_store_order','eb_store_order_cart_info','eb_marketplace_stock_movement',
            'eb_marketplace_order_idempotency','eb_store_product_log']as$table)m4c_expect($pre['facts'][$table]===[],'M4C_H5_STAGE_B0_EMPTY_BUSINESS_PRODUCERS');
        $business=['baseline_rows_equal'=>true,'base_user_equal'=>true,'empty_cart_order'=>true];
    }elseif($stage==='B1'){
        m4cb_preserved($previous['facts'],$pre['facts'],$uid,[],true);m4ch_stage_auth_captures($all,$uid);
        m4c_expect($pre['facts']['eb_store_cart']===[]&&$pre['facts']['eb_store_order']===[],'M4C_H5_STAGE_B1_LOGIN_ONLY_NO_CART_ORDER');
        $business=['owner_auth_only'=>true,'actual_login_capture'=>true,'actual_user_capture'=>true];
    }elseif($stage==='B2'){
        m4cb_preserved($previous['facts'],$pre['facts'],$uid,['eb_store_cart','eb_store_product_log']);
        m4ch_stage_auth_captures($all,$uid);m4ch_stage_carts($pre,$all,$oracle,$runtime);m4ch_stage_producer_stats($pre,$all,$oracle,$runtime);
        $stores=[];$products=[];
        foreach(m4ch_stage_captures($all,'/api/marketplace/stores/:merchant')as$capture){$data=m4ch_stage_data($capture);$mer=(int)($data['mer_id']??0);
            m4c_expect(isset($oracle['fixture']['merchants'][$mer])&&($data['name']??null)===$oracle['fixture']['merchants'][$mer]['name'],
                'M4C_H5_STAGE_TWO_ACTUAL_STORE_RESPONSES');$stores[$mer]=true;}
        foreach(m4ch_stage_captures($all,'/api/product/detail/:product')as$capture){$data=m4ch_stage_data($capture);$products[(int)$data['storeInfo']['id']]=true;}
        m4c_expect(count($stores)===2&&count($products)===3,'M4C_H5_STAGE_BOTH_SHOPS_THREE_PRODUCTS_ACTUALLY_VISITED');
        m4ch_storefront_captures($all,$oracle);
        $business=['cart_rows_verified'=>true,'visit_logs_verified'=>true,'auxiliary_producers_verified'=>true];
    }elseif($stage==='B3'){
        m4cb_preserved($previous['facts'],$pre['facts'],$uid);m4ch_stage_auth_captures($all,$uid);
        $byProduct=m4ch_stage_carts($pre,$all,$oracle,$runtime);m4ch_stage_producer_stats($pre,$all,$oracle,$runtime);
        m4ch_stage_app_attempt($source,$runtime,$request);$default=m4ch_stage_default_pdo($reader);
        $postApp=m4ch_stage_snapshot($reader);m4c_expect($postApp===$pre,'M4C_H5_STAGE_B3_REAL_APP_INITIALIZATION_ALL_ROWS_UNCHANGED');
        $confirmation=m4ch_stage_frame($all,$source,$oracle,$runtime,$byProduct);$postRead=m4ch_stage_snapshot($reader);
        m4ch_stage_default_stable($reader,$default);m4c_expect($postRead===$pre,'M4C_H5_STAGE_B3_CACHE_READ_ALL_ROWS_UNCHANGED');
        $encoded=m4ch_stage_serialized($pre);$snapshot=$pre;
        $business=['before_create_snapshot_sha256'=>$encoded['snapshot_sha256'],'confirmation_key'=>$confirmation['key'],
            'confirmation_frame'=>$confirmation['frame'],'app_initialization_rows_unchanged'=>true,'frame_read_rows_unchanged'=>true,
            'native_independent_pdo_verified'=>true];
    }else{
        $stop=m4ch_http_child_stop($source,$runtime);m4c_expect(($stop['action']??null)==='stop'&&($stop['status']??null)==='stop'
            &&($stop['term_attempted']??null)===true&&($stop['remaining_members']??null)===[]&&($stop['listener_open']??null)===false
            &&($stop['native_child']['running']??null)===false&&is_int($stop['native_proc_close']??null),
            'M4C_H5_STAGE_B4_ORIGINAL_CHILD_ACTUALLY_STOPPED');
        $stopPath=M4CH_STAGE_CONTROL.'/supervisor-responses/'.$stop['step_nonce'].'.json';$stopMeta=m4ch_http_meta($stopPath);
        $b3=$prior[3];m4c_expect($b3['stage']==='B3'&&$b3['business']['before_create_snapshot_sha256']===$b3['snapshot_sha256'],
            'M4C_H5_STAGE_B4_ORIGINAL_B3_BEFORE_CREATE_SNAPSHOT');
        $frame=$b3['business']['confirmation_frame'];$key=$b3['business']['confirmation_key'];$creates=m4ch_stage_captures([$request],'/api/order/create/:confirmation');
        m4c_expect(count($creates)===1,'M4C_H5_STAGE_EXACTLY_ONE_ACTUAL_UI_CREATE');$create=$creates[0];
        m4c_expect(rawurldecode(basename(parse_url($create['request']['url'],PHP_URL_PATH)))===$key,
            'M4C_H5_STAGE_REAL_CREATE_ORIGINAL_ROUTE_KEY');
        m4ch_stage_app_attempt($source,$runtime,$request);$default=m4ch_stage_default_pdo($reader);$postApp=m4ch_stage_snapshot($reader);
        m4c_expect($postApp===$pre,'M4C_H5_STAGE_B4_REAL_APP_INITIALIZATION_ALL_ROWS_UNCHANGED');
        $input=MarketplaceOrderInput::create($uid,$key,$create['request']['body']);
        m4c_expect($input['request']['mer_id']===(int)$oracle['fixture']['merchant']['mer_id']
            &&$input['request']['source_kind']==='persistent'&&$input['request']['cart_refs']===$frame['cart_refs']
            &&$input['idem_key']==='confirm:'.$key,'M4C_H5_STAGE_ACTUAL_PRODUCTION_CREATE_INPUT_AND_IDEMPOTENCY');
        m4ch_stage_answers($input['request'],$frame,$oracle);$number=m4cb_wire_created(m4ch_stage_data($create),$key);
        $saved=m4cb_saved($reader,$oracle['fixture'],$input['request'],$input['idem_key'],$frame,
            $input['request']['options']['custom_form'],$number,$previous['facts'],$oracle['ui_intent']['unselected_cart_line']);
        $postSaved=m4ch_stage_snapshot($reader);m4ch_stage_default_stable($reader,$default);
        m4c_expect($saved===$postSaved['facts']&&$postSaved===$pre,'M4C_H5_STAGE_B4_SAVED_READBACK_ALL_ROWS_UNCHANGED');
        m4ch_stage_auxiliary_equal($baseline,$postSaved);m4ch_stage_auth_captures($all,$uid);
        m4ch_stage_detail([$request],$frame,$input,$oracle,$number);
        m4c_expect(m4ch_http_meta($stopPath)===$stopMeta,'M4C_H5_STAGE_B4_ORIGINAL_PRIVATE_STOP_UNCHANGED');$snapshot=$postSaved;
        $business=['child_stop_sha256'=>$stopMeta['sha256'],'saved_order_verified'=>true,
            'app_initialization_rows_unchanged'=>true,'saved_readback_rows_unchanged'=>true];
    }
    m4cb_guard_readonly();m4c_expect(m4cb_events($reader)['custom_event_count']===0&&m4cb_rows($reader,
        'SELECT * FROM `eb_system_event` WHERE `mark` = ? AND `is_del` = 0 AND `is_open` = 1',['user_product_visit'])===[],
        'M4C_H5_STAGE_REAL_EVENT_PRODUCER_PRECONDITIONS');
    m4ch_storefront_advance_equal($reader,$oracle);
    m4c_expect(m4ch_stage_snapshot($reader)===$snapshot,'M4C_H5_STAGE_FINAL_NATIVE_READBACK_UNCHANGED');
    m4c_expect($loaded['metadata']===['baseline'=>m4ch_http_meta(M4CH_STAGE_CONTROL.'/sql-baseline.json'),
        'oracle'=>m4ch_http_meta(M4CH_STAGE_CONTROL.'/customer-oracle.json')],'M4C_H5_STAGE_ORIGINAL_SEALED_PRODUCERS_UNCHANGED');
    $value=m4ch_http_header($request,'MS4_CUSTOMER_H5_PRIVATE_STAGE')+['stage'=>$stage,'network'=>$request['network'],'captures'=>$request['captures']]
        +m4ch_stage_serialized($snapshot)+['business'=>$business];
    $meta=m4ch_http_publish(M4CH_STAGE_CONTROL.'/stage-'.$stage.'.json',$value);
    return m4ch_http_header($request,'MS4_CUSTOMER_H5_SQL_STAGE_RESULT')+['stage'=>$stage,'business_table_count'=>27,
        'auxiliary_table_count'=>7,'private_stage_sha256_prefix'=>substr($meta['sha256'],0,12)];
}

/** Final independent native read only. The entry publishes only after its final full-source/meta gates pass. */
function m4ch_stage_final(array $source,array $runtime): array
{
    m4cb_phase('business_h5_final_native');m4c_expect(empty($GLOBALS['m4c_app_started']),'M4C_H5_STAGE_FINAL_NO_SECOND_APP');
    $loaded=m4ch_stage_sealed($source);$b4=m4ch_http_private_read(M4CH_STAGE_CONTROL.'/stage-B4.json');
    m4ch_stage_keys($b4,['schema','scope',...M4CH_STAGE_IDENTITIES,'stage','network','captures','snapshot_base64','snapshot_sha256','business']);
    m4ch_http_identity($b4,$source,$runtime);m4c_expect($b4['scope']==='MS4_CUSTOMER_H5_PRIVATE_STAGE'&&$b4['stage']==='B4'
        &&($b4['business']['saved_order_verified']??null)===true,'M4C_H5_STAGE_FINAL_REAL_B4_SAVED_PROOF');
    $before=m4ch_stage_unserialize($b4);$stop=m4ch_http_child_stop($source,$runtime);
    m4c_expect(m4ch_http_meta(M4CH_STAGE_CONTROL.'/supervisor-responses/'.$stop['step_nonce'].'.json')['sha256']===$b4['business']['child_stop_sha256'],
        'M4C_H5_STAGE_FINAL_ORIGINAL_CHILD_STOP_BINDING');
    $closed=m4ch_http_private_read(M4CH_STAGE_CONTROL.'/browser-closed.json',16384);m4ch_http_identity($closed,$source,$runtime);
    m4c_expect($closed['scope']==='MS4_CUSTOMER_H5_BROWSER_CLOSED'&&($closed['viewport_reset']??null)===true
        &&is_array($closed['closed_owned_tab_ids']??null)&&count($closed['closed_owned_tab_ids'])===1,
        'M4C_H5_STAGE_FINAL_ACTUAL_BROWSER_CLOSED');
    $reader=audit_db();m4c_expect(!$reader->inTransaction(),'M4C_H5_STAGE_FINAL_INDEPENDENT_NATIVE_READER');
    $actual=m4ch_stage_snapshot($reader);m4c_expect($actual===$before,'M4C_H5_STAGE_FINAL_ALL_THIRTY_FOUR_TABLES_EQUAL_B4');
    m4ch_stage_auxiliary_equal($loaded['baseline'],$actual);m4ch_storefront_advance_equal($reader,$loaded['oracle']);m4cb_guard_readonly();
    m4c_expect(m4ch_stage_snapshot($reader)===$actual&&$loaded['metadata']===['baseline'=>m4ch_http_meta(M4CH_STAGE_CONTROL.'/sql-baseline.json'),
        'oracle'=>m4ch_http_meta(M4CH_STAGE_CONTROL.'/customer-oracle.json')],'M4C_H5_STAGE_FINAL_NATIVE_AND_SEALED_METADATA_UNCHANGED');
    return m4ch_http_header($closed,'MS4_CUSTOMER_H5_SQL_AUDIT')+['verified'=>true,'business_table_count'=>27,'auxiliary_table_count'=>7,
        'private_snapshot_sha256_prefix'=>substr(hash('sha256',serialize($actual)),0,12),'full_source_unchanged'=>true,
        'scope_limit'=>'OBSERVED_27_BUSINESS_PLUS_7_AUXILIARY_TABLES_ONLY'];
}