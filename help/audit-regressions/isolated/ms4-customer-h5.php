<?php
declare(strict_types=1);

/** Finite H5 preparation only. Requiring the original entry does not execute its guarded main. */
require_once __DIR__.'/ms4-customer-http-normal.php';
require_once __DIR__.'/lib/ms4-customer-h5-normal.php';

const M4CH_ENTRY='help/audit-regressions/isolated/ms4-customer-h5.php';
const M4CH_ROLE='audit-ms4-customer-h5-prepare';
const M4CH_PROOF='customer-h5-prepare';
const M4CH_SOURCE_FILES=['help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php',M4CH_ENTRY];
const M4CH_MANIFESTS=['public_source'=>'/run/ms4-prep/manifests/public-source.json',
    'dist'=>'/run/ms4-prep/manifests/h5-dist.json','inputs'=>'/run/ms4-prep/manifests/inputs.json'];
const M4CH_INPUTS=[
    'language'=>['path'=>'/run/ms4-prep/inputs/language.json','bytes'=>318204,
        'sha256'=>'9e6aab1cf67ea492d137b12052220b0e710fb7fe16f3b5f93135a6693945c716'],
    'theme'=>['path'=>'/run/ms4-prep/inputs/theme.json','bytes'=>102429,
        'sha256'=>'8f879a5f7775d39cf190023aca1ea49e7cacf7a680277e10a0712d0796ba1bc1']
];

function m4ch_entry_relative(string $path): void
{
    m4c_expect($path!==''&&preg_match('~(^/|\\\\|[\x00-\x1f\x7f]|(^|/)\.\.?(/|$)|//|:|/$)~',$path)!==1,
        'M4C_H5_CANONICAL_RELATIVE_MATERIAL_PATH');
}
function m4ch_entry_rows(array $rows): array
{
    m4c_expect(array_is_list($rows)&&$rows!==[],'M4C_H5_NONEMPTY_DESCRIPTOR_LIST');$paths=[];
    foreach($rows as$row){m4c_expect(is_array($row),'M4C_H5_DESCRIPTOR_OBJECT');m4c_entry_keys($row,['path','bytes','sha256']);
        m4c_expect(is_string($row['path'])&&is_int($row['bytes'])&&$row['bytes']>=0&&is_string($row['sha256'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])===1,'M4C_H5_CANONICAL_FILE_DESCRIPTOR');
        m4ch_entry_relative($row['path']);$paths[]=$row['path'];}
    $sorted=$paths;sort($sorted,SORT_STRING);m4c_expect($paths===$sorted&&count($paths)===count(array_unique($paths,SORT_STRING)),
        'M4C_H5_SORTED_UNIQUE_FILE_DESCRIPTORS');return $rows;
}
function m4ch_entry_directories(array $directories): array
{
    m4c_expect(array_is_list($directories),'M4C_H5_DIRECTORY_LIST');
    foreach($directories as$path){m4c_expect(is_string($path),'M4C_H5_DIRECTORY_STRING');m4ch_entry_relative($path);}
    $sorted=$directories;sort($sorted,SORT_STRING);
    m4c_expect($directories===$sorted&&count($directories)===count(array_unique($directories,SORT_STRING)),
        'M4C_H5_SORTED_UNIQUE_DIRECTORIES');return $directories;
}
/** Full actual tree, including empty directories; the optional owner is the copied public publisher. */
function m4ch_entry_tree(string $root,array $files,array $directories,?int $owner=null): void
{
    m4c_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'M4C_H5_REAL_MATERIAL_ROOT');
    if($owner!==null)m4c_expect(fileowner($root)===$owner&&(fileperms($root)&0777)===0755,'M4C_H5_PUBLIC_ROOT_OWNER_MODE');
    $actual=[];$actualDirectories=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST)as$file){$path=$file->getPathname();$relative=substr($path,strlen($root)+1);
        m4ch_entry_relative($relative);clearstatcache(true,$path);$before=lstat($path);
        m4c_expect(!$file->isLink()&&is_array($before)&&realpath($path)===$path
            &&in_array($before['mode']&0170000,[0040000,0100000],true),'M4C_H5_NO_LINK_OR_SPECIAL_MATERIAL');
        $isDirectory=($before['mode']&0170000)===0040000;
        if($owner!==null)m4c_expect($before['uid']===$owner&&($before['mode']&0777)===($isDirectory?0755:0644),
            'M4C_H5_ALL_PUBLIC_ACTUAL_OWNER_MODES');
        if($isDirectory){$actualDirectories[]=$relative;continue;}
        $hash=hash_file('sha256',$path);clearstatcache(true,$path);$after=lstat($path);
        $keys=array_flip(['dev','ino','mode','uid','gid','size','mtime','ctime']);
        m4c_expect(is_string($hash)&&is_array($after)&&array_intersect_key($before,$keys)===array_intersect_key($after,$keys),
            'M4C_H5_FULL_FILE_READ_IDENTITY_UNCHANGED');
        $actual[]=['path'=>$relative,'bytes'=>$before['size'],'sha256'=>$hash];}
    usort($actual,static fn(array$a,array$b):int=>strcmp($a['path'],$b['path']));sort($actualDirectories,SORT_STRING);
    m4c_expect($actual===$files&&$actualDirectories===$directories,'M4C_H5_COMPLETE_ACTUAL_FILES_AND_EMPTY_DIRECTORIES');
}
function m4ch_entry_json(array $descriptor,string $fixedPath): array
{
    m4c_entry_keys($descriptor,['path','bytes','sha256']);
    m4c_expect($descriptor['path']===$fixedPath&&is_int($descriptor['bytes'])&&$descriptor['bytes']>0
        &&$descriptor['bytes']<=16777216&&is_string($descriptor['sha256'])
        &&preg_match('/\A[a-f0-9]{64}\z/D',$descriptor['sha256'])===1&&is_file($fixedPath)&&!is_link($fixedPath)
        &&realpath($fixedPath)===$fixedPath,'M4C_H5_FIXED_BOUND_PUBLIC_JSON');
    clearstatcache(true,$fixedPath);$before=lstat($fixedPath);$raw=file_get_contents($fixedPath);clearstatcache(true,$fixedPath);$after=lstat($fixedPath);
    $keys=array_flip(['dev','ino','mode','uid','gid','size','mtime','ctime']);
    m4c_expect(is_array($before)&&is_array($after)&&array_intersect_key($before,$keys)===array_intersect_key($after,$keys)
        &&is_string($raw)&&strlen($raw)===$descriptor['bytes']&&hash('sha256',$raw)===$descriptor['sha256'],
        'M4C_H5_FULL_PUBLIC_JSON_BYTES_AND_IDENTITY');
    $value=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
    m4c_expect(is_array($value)&&!array_is_list($value),'M4C_H5_BOUND_PUBLIC_JSON_OBJECT');return $value;
}
/** The host binds reviewed build provenance; this gate verifies all bound copied bytes, not a compiler claim. */
function m4ch_entry_materials(array $source): array
{
    $materials=$source['h5_materials'];m4c_entry_keys($materials,['runtime_origin','public_source','dist','inputs','fixture_png']);
    m4c_expect(is_string($materials['runtime_origin'])
        &&preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D',$materials['runtime_origin'],$port)===1
        &&(int)$port[1]<=65535,'M4C_H5_FROZEN_LOOPBACK_RUNTIME_ORIGIN');$manifests=[];
    foreach(M4CH_MANIFESTS as$name=>$path){m4c_expect(is_array($materials[$name]),'M4C_H5_MANIFEST_DESCRIPTOR_OBJECT');
        $manifests[$name]=m4ch_entry_json($materials[$name],$path);}
    foreach(['public_source','dist']as$name){$manifest=$manifests[$name];m4c_entry_keys($manifest,['schema','files','directories']);
        m4c_expect($manifest['schema']===1&&is_array($manifest['files'])&&is_array($manifest['directories']),
            'M4C_H5_FIXED_COMPLETE_TREE_MANIFEST');m4ch_entry_rows($manifest['files']);m4ch_entry_directories($manifest['directories']);}
    $inputManifest=$manifests['inputs'];m4c_entry_keys($inputManifest,['schema','language','theme']);
    m4c_expect($inputManifest['schema']===1,'M4C_H5_FIXED_INPUT_MANIFEST_SCHEMA');
    foreach(M4CH_INPUTS as$name=>$descriptor){m4c_expect(is_array($inputManifest[$name]),'M4C_H5_APPROVED_INPUT_DESCRIPTOR');
        m4c_entry_keys($inputManifest[$name],['path','bytes','sha256']);
        m4c_expect($inputManifest[$name]===$descriptor,'M4C_H5_REVIEWED_LANGUAGE_AND_THEME_INPUT_BYTES');
        m4ch_entry_json($descriptor,$descriptor['path']);}
    $public=$manifests['public_source'];$dist=$manifests['dist'];$expected=[];$directories=[];
    foreach([...array_column($public['files'],'path'),...$public['directories']]as$path)
        m4c_expect($path!=='install.lock'&&!str_starts_with($path,'__ms4_assets')&&!str_starts_with($path,'__ms4_fixture__'),
            'M4C_H5_NO_SOURCE_MARKER_OR_RESERVED_ASSET_COLLISION');
    foreach($public['files']as$row)$expected[$row['path']]=$row;
    foreach($public['directories']as$directory)$directories[$directory]=true;
    m4c_expect(in_array('index.html',array_column($dist['files'],'path'),true),'M4C_H5_REAL_DIST_INDEX_PRESENT');
    foreach($dist['files']as$row){$path='__ms4_assets/h5/'.$row['path'];
        m4c_expect(!isset($expected[$path]),'M4C_H5_NO_DIST_SOURCE_COLLISION');$expected[$path]=['path'=>$path,'bytes'=>$row['bytes'],'sha256'=>$row['sha256']];}
    foreach($dist['directories']as$directory)$directories['__ms4_assets/h5/'.$directory]=true;
    $png=$materials['fixture_png'];m4c_expect(is_array($png),'M4C_H5_FIXED_PNG_DESCRIPTOR');m4c_entry_keys($png,['path','bytes','sha256']);
    m4c_expect($png['path']==='/run/ms4-prep/ms4-order.png'&&is_int($png['bytes'])&&$png['bytes']>0&&$png['bytes']<=2097152
        &&is_string($png['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$png['sha256'])===1
        &&is_file($png['path'])&&!is_link($png['path'])&&realpath($png['path'])===$png['path']
        &&filesize($png['path'])===$png['bytes']&&hash_file('sha256',$png['path'])===$png['sha256'],
        'M4C_H5_ACTUAL_FROZEN_FIXTURE_PNG_BYTES');
    $marker='# AUDIT-RUN '.$source['run_id']."\n";
    $expected['install.lock']=['path'=>'install.lock','bytes'=>strlen($marker),'sha256'=>hash('sha256',$marker)];
    $expected['__ms4_fixture__/product.png']=['path'=>'__ms4_fixture__/product.png','bytes'=>$png['bytes'],'sha256'=>$png['sha256']];
    foreach(array_keys($expected)as$path){$parent=dirname($path);while($parent!=='.'){$directories[$parent]=true;$parent=dirname($parent);}}
    ksort($expected,SORT_STRING);$expected=array_values($expected);$directories=array_keys($directories);sort($directories,SORT_STRING);
    $copy=m4ch_entry_json($source['public_copy'],'/run/audit/evidence/customer-h5-public-copy.json');
    m4c_entry_keys($copy,['schema','scope','run_id','source_sha','freeze_sha256','source_identity','read_only_source',
        'source_count','h5_count','public_count','files','directories','inputs_unchanged','closed_set_verified','marker_sha256','png_sha256','permissions']);
    m4c_expect($copy['schema']===1&&$copy['scope']==='MS4_CURRENT_H5_PUBLIC_SNAPSHOT_ONLY'&&$copy['run_id']===$source['run_id']
        &&$copy['source_sha']===$source['source_sha']&&$copy['freeze_sha256']===$source['freeze_sha256']
        &&$copy['read_only_source']===true&&$copy['inputs_unchanged']===true&&$copy['closed_set_verified']===true,
        'M4C_H5_SOURCE_BOUND_PUBLIC_COPY_RECEIPT');
    $identity=$copy['source_identity'];m4c_expect(is_array($identity),'M4C_H5_PUBLIC_COPY_NATIVE_IDENTITY_OBJECT');
    m4c_entry_keys($identity,['proof_name','role','command','container_id','native_created_sha256']);
    $copyCommand=['/run/ms4-prep/ms4-h5-public-copy.php','copy'];
    m4c_expect($identity['proof_name']==='customer-h5-public-copy'&&$identity['role']==='audit-ms4-customer-h5-public-copy'
        &&$identity['command']===$copyCommand,'M4C_H5_FINITE_PUBLIC_COPY_NATIVE_PROOF');
    $created=m4c_entry_terminal($source,'customer-h5-public-copy','audit-ms4-customer-h5-public-copy',$copyCommand);
    m4c_expect($identity['container_id']===$created['id']&&$identity['native_created_sha256']
        ===hash_file('sha256','/run/audit/evidence/customer-h5-public-copy-created.json'),'M4C_H5_ORIGINAL_PUBLIC_COPY_TERMINAL_BINDING');
    foreach(['/source'=>[$source['context']['repo_dir'],false],'/workspace/crmeb/public'=>['http-public',true]]as$target=>$wanted){
        $matches=array_values(array_filter($created['mounts'],static fn(array$row):bool=>$row['Destination']===$target));
        m4c_expect(count($matches)===1&&$matches[0]['RW']===$wanted[1],'M4C_H5_PUBLIC_COPY_ACTUAL_SOURCE_VOLUME_ACCESS');$mount=$matches[0];
        if($target==='/source')m4c_expect($mount['Type']==='bind'&&audit_canonical_host_bind_source($mount['Source'])
            ===audit_canonical_host_bind_source($wanted[0]),'M4C_H5_PUBLIC_COPY_REAL_READONLY_SOURCE');
        else m4c_expect($mount['Type']==='volume'&&$mount['Name']===$source['context']['project'].'_'.$wanted[0],
            'M4C_H5_PUBLIC_COPY_SAME_NATIVE_PUBLIC_VOLUME');}
    m4c_expect($copy['source_count']===count($public['files'])&&$copy['h5_count']===count($dist['files'])
        &&$copy['public_count']===count($expected)&&$copy['files']===$expected&&$copy['directories']===$directories
        &&$copy['marker_sha256']===hash('sha256',$marker)&&$copy['png_sha256']===$png['sha256'],
        'M4C_H5_FULL_COPIED_PUBLIC_MANIFEST_NOT_COUNTS_ONLY');
    $permissions=$copy['permissions'];m4c_expect(is_array($permissions),'M4C_H5_PUBLIC_PERMISSIONS_OBJECT');
    m4c_entry_keys($permissions,['publisher_uid','directory_mode','file_mode','verified']);
    m4c_expect(is_int($permissions['publisher_uid'])&&$permissions['publisher_uid']>=0&&$permissions['directory_mode']==='0755'
        &&$permissions['file_mode']==='0644'&&$permissions['verified']===true,'M4C_H5_PUBLIC_DECLARED_OWNER_MODES');
    m4ch_entry_tree('/source/crmeb/public',$public['files'],$public['directories']);
    m4ch_entry_tree('/workspace/crmeb/public',$expected,$directories,$permissions['publisher_uid']);
    return ['runtime_origin'=>$materials['runtime_origin'],'public_copy_sha256'=>$source['public_copy']['sha256'],
        'public_source_sha256'=>$materials['public_source']['sha256'],'inputs'=>M4CH_INPUTS];
}
function m4ch_entry_full_source(array $source): void
{
    m4c_expect(hash_file('sha256','/run/audit/preparation-source-binding.json')===$source['freeze_sha256']
        &&hash_file('sha256','/run/audit/evidence/ms4-customer-h5-prepare-source-binding.json')===$source['source_binding_sha256']
        &&hash_file('sha256','/run/audit/evidence/customer-h5-prepare-environment.json')===$source['environment_sha256']
        &&hash_file('sha256','/run/audit/evidence/customer-h5-prepare-created.json')===$source['native_created_sha256'],
        'M4C_H5_FROZEN_ENTRY_PUBLIC_IDENTITY_BYTES_UNCHANGED');
    m4c_entry_full_source($source);
    $rows=m4ch_entry_rows($source['h5_source_files']);
    m4c_expect(array_column($rows,'path')===M4CH_SOURCE_FILES,'M4C_H5_EXACT_TWO_ADDITIONAL_PHP_SOURCES');
    foreach(['/source','/workspace']as$root)foreach($rows as$row){$path=$root.'/'.$row['path'];
        m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path&&filesize($path)===$row['bytes']
            &&hash_file('sha256',$path)===$row['sha256'],'M4C_H5_BOTH_ROOTS_ACTUAL_ADDITIONAL_SOURCE_BYTES');}
}
function m4ch_entry_source(): array
{
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&($_SERVER['argv']??[])===[$_SERVER['SCRIPT_FILENAME'],'prepare']
        &&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','M4C_H5_ACTUAL_FINITE_PREPARE_ENTRY');
    $evidence='/run/audit/evidence/';$context=m4c_entry_read('/run/audit/actual-run-context.json');
    $freeze=m4c_entry_read('/run/audit/preparation-source-binding.json');$created=m4c_entry_read($evidence.M4CH_PROOF.'-created.json');
    $bindingPath=$evidence.'ms4-customer-h5-prepare-source-binding.json';$binding=m4c_entry_read($bindingPath);
    $environmentPath=$evidence.'customer-h5-prepare-environment.json';$environment=m4c_entry_read($environmentPath);$manifest=audit_manifest();
    $run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');$cid=$created['id']??null;
    m4c_expect(getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'
        &&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run)===1&&preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha)===1
        &&$context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$manifest['run_id']===$run&&$manifest['commit_sha']===$sha
        &&$manifest['project']===$context['project']&&$manifest['isolation']==='ISO-FULL'
        &&$manifest['guard_mode']==='read-only'&&$manifest['network_internal']===true,'M4C_H5_SAME_REAL_ISOLATION_SOURCE');
    $roles=array_keys($manifest['services']);sort($roles,SORT_STRING);$wanted=[M4CH_ROLE,'audit-mysql','audit-redis'];sort($wanted,SORT_STRING);
    m4c_expect($roles===$wanted&&is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1
        &&$manifest['services'][M4CH_ROLE]['id']===$cid&&gethostname()===substr($cid,0,12),'M4C_H5_ONLY_THREE_ACTUAL_PREPARE_ROLE_IDENTITIES');
    $freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');$sortedExtra=M4C_EXTRA;sort($sortedExtra,SORT_STRING);
    m4c_expect($context['freeze_sha256']===$freezeHash&&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY'
        &&$freeze['customer_source_roots']===M4C_ROOTS&&$freeze['customer_source_extra']===$sortedExtra,
        'M4C_H5_ORIGINAL_CUSTOMER_SOURCE_CONTRACT_UNCHANGED');
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','h5_source_files','h5_materials','public_copy']);
    m4c_expect($binding['schema']===1&&$binding['scope']==='MS4_CUSTOMER_H5_PREPARE_SOURCE'&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.M4CH_PROOF.'-created.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['source_files']===$freeze['customer_source_files']
        &&$binding['h5_source_files']===$freeze['h5_source_files']&&$binding['h5_materials']===$freeze['h5_materials']
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__),'M4C_H5_ORIGINAL_CREATED_AND_COMPLETE_FROZEN_BINDING');
    m4c_expect($created['image']===M4C_IMAGE&&$created['run_id']===$run&&$created['source_sha']===$sha&&$created['proof_name']===M4CH_PROOF
        &&$created['hostname']===gethostname()&&$created['read_only']===true&&$created['privileged']===false
        &&$created['status']==='created'&&$created['pid']===0&&$created['restart_count']===0&&$created['entrypoint']===['php']
        &&$created['command']===[M4CH_ENTRY,'prepare']&&$created['workdir']==='/workspace'&&$created['cap_drop']===['ALL']
        &&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true),'M4C_H5_REAL_CREATED_PREPARE_PHP_ROLE');
    $labels=$created['labels'];m4c_expect($labels['tianli.audit']==='1'&&$labels['tianli.audit.run']===$run
        &&$labels['com.docker.compose.project']===$context['project']&&$labels['com.docker.compose.service']===M4CH_ROLE,
        'M4C_H5_ORIGINAL_NATIVE_PREPARE_LABELS');
    $network=m4c_entry_read('/run/audit/network.json');
    m4c_expect($network===$context['initial_network']&&$network['Internal']===true&&$network['Id']===$context['network_id']
        &&$created['network_mode']===$network['Name']&&array_keys($created['networks'])===[$network['Name']]
        &&in_array($created['networks'][$network['Name']]['NetworkID'],['',$network['Id']],true)
        &&in_array($created['port_bindings'],[null,[]],true),'M4C_H5_ONE_ORIGINAL_INTERNAL_NETWORK_NO_PORT_BINDINGS');
    m4c_expect($created['ports']===null||is_array($created['ports']),'M4C_H5_NATIVE_PORTS_OBJECT_OR_NULL');
    foreach($created['ports']??[]as$mapping)m4c_expect(in_array($mapping,[null,[]],true),'M4C_H5_NO_PUBLISHED_NATIVE_PORT');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],false]];
    $volumes=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',false],
        '/workspace/crmeb/public'=>['http-public',false],'/workspace/crmeb/runtime'=>['http-runtime',true],
        '/run/audit-http-control'=>['http-control',true],'/run/audit-http-browser'=>['http-browser-secrets',true]];
    $nativeMounts=[];$tmpMounts=[];
    foreach($created['mounts']as$mount){if($mount['Type']!=='tmpfs'){$nativeMounts[]=$mount;continue;}
        m4c_expect($mount['Destination']==='/tmp'&&$mount['RW']===true&&!isset($tmpMounts['/tmp']),
            'M4C_H5_ONLY_ONE_NATIVE_TMPFS');$tmpMounts['/tmp']=true;}
    m4c_expect(count($nativeMounts)===count($binds)+count($volumes),'M4C_H5_CLOSED_PREPARE_MOUNT_SET');
    foreach(array_merge($binds,$volumes)as$target=>$expected){$matches=array_values(array_filter($nativeMounts,
        static fn(array$row):bool=>$row['Destination']===$target));m4c_expect(count($matches)===1,'M4C_H5_ONE_EXACT_NATIVE_PREPARE_MOUNT');
        $mount=$matches[0];m4c_expect($mount['RW']===$expected[1],'M4C_H5_PREPARE_MOUNT_ACCESS');
        if(isset($binds[$target]))m4c_expect($mount['Type']==='bind'&&audit_canonical_host_bind_source($mount['Source'])
            ===audit_canonical_host_bind_source($expected[0]),'M4C_H5_PREPARE_ORIGINAL_HOST_BIND');
        else m4c_expect($mount['Type']==='volume'&&$mount['Name']===$context['project'].'_'.$expected[0],'M4C_H5_PREPARE_ORIGINAL_NATIVE_VOLUME');}
    m4c_expect(is_array($created['tmpfs'])&&array_keys($created['tmpfs'])===['/tmp']
        &&is_string($created['tmpfs']['/tmp'])&&in_array('rw',explode(',',$created['tmpfs']['/tmp']),true),
        'M4C_H5_EXACT_SINGLE_NATIVE_TMPFS');
    m4c_expect(!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/public/install.lock'),'M4C_H5_NO_HOST_RUNTIME_CONFIGURATION');
    m4c_entry_keys($environment,['schema','scope','run_id','source_sha','container_id','freeze_sha256','environment','native_projection_sha256']);
    m4c_expect($environment['schema']===1&&$environment['scope']==='MS4_CUSTOMER_H5_REAL_ENVIRONMENT'
        &&$environment['run_id']===$run&&$environment['source_sha']===$sha&&$environment['container_id']===$cid
        &&$environment['freeze_sha256']===$freezeHash,'M4C_H5_ACTUAL_PREPARE_ENVIRONMENT_BINDING');
    $values=$environment['environment'];m4c_entry_keys($values,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED',
        'PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER']);
    m4c_expect($values['APP_ENV']==='testing'&&$values['PHP_MARKETPLACE_ENABLED']==='true'&&$values['PHP_MARKETPLACE_TRADE_ENABLED']==='true'
        &&$values['PHP_CACHE_DRIVER']==='redis'&&is_string($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])
        &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])===1
        &&bccomp($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'4294967295',0)<=0,'M4C_H5_REAL_ENABLED_PUBLIC_ENVIRONMENT_VALUES');
    foreach($values as$key=>$value)m4c_expect(getenv($key)===$value,'M4C_H5_ACTUAL_PROCESS_ENVIRONMENT');
    $native=m4c_entry_read($evidence.M4CH_PROOF.'-environment-native.json');
    m4c_expect($native['native_exit']===0&&$native['stderr_bytes']===0&&$native['stderr_sha256']===hash('sha256','')
        &&is_int($native['stdout_bytes'])&&$native['stdout_bytes']>0&&$native['stdout_sha256']===$environment['native_projection_sha256'],
        'M4C_H5_REAL_NATIVE_ENVIRONMENT_PROJECTION');
    $source=$binding+['mode'=>'prepare','rule'=>['role'=>M4CH_ROLE,'proof'=>M4CH_PROOF,'scope'=>'NORMAL_CUSTOMER_H5_PREPARATION_ONLY',
        'status'=>'VERIFIED_CUSTOMER_H5_PREPARATION_ONLY'],'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'context'=>$context,'freeze'=>$freeze,
        'source_binding_sha256'=>hash_file('sha256',$bindingPath),'environment_sha256'=>hash_file('sha256',$environmentPath)];
    m4ch_entry_full_source($source);
    $bootstrap=m4c_entry_terminal($source,'files-bootstrap','audit-bootstrap',['/run/ms4-prep/ms4-initialize.php','files-bootstrap']);
    $installed=m4c_entry_read($evidence.'files-bootstrap-result.json');$installation=$installed['installation'];
    $shutdown=m4c_entry_read($evidence.'files-bootstrap-shutdown.json');$initializer=m4c_entry_read($evidence.'initializer-result.json');
    m4c_expect($installed['schema']===1&&$installed['scope']==='MS4_PREREQUISITES_ONLY'
        &&$installed['status']==='VERIFIED_CUSTOMER_FILES_BOOTSTRAP_ONLY'&&$installed['native_exit']===0
        &&$installed['run_id']===$run&&$installed['source_sha']===$sha&&$installed['binding']['source_sha']===$sha
        &&$installed['binding']['container_id']===$bootstrap['id']&&$installed['binding']['freeze_sha256']===$freezeHash
        &&$installed['binding']['created_sha256']===hash_file('sha256',$evidence.'files-bootstrap-created.json')
        &&$installed['actual_self_mer_id']===$source['self_operated_mer_id']&&$initializer['actual_self_mer_id']===$source['self_operated_mer_id']
        &&$installed['initializer_result_sha256']===hash_file('sha256',$evidence.'initializer-result.json')
        &&$installed['migration_result_sha256']===hash_file('sha256',$evidence.'ms4-migration-normal.json')
        &&$installation['account']==='audit_migrator'&&is_int($installation['checksum_rows'])&&$installation['checksum_rows']>0
        &&$installation['source_matches']===true&&$installation['version_marker_matches']===true
        &&$installation['application_account']==='audit_app'&&$installation['application_dml_only']===true
        &&$shutdown['phase']==='files-bootstrap'&&$shutdown['fatal']===false&&$shutdown['primary_failure']===null
        &&$shutdown['cleanup_failure']===null,'M4C_H5_REAL_ORIGINAL_DEDICATED_FILES_BOOTSTRAP');
    $prepared=m4c_entry_terminal($source,'customer-prepare',M4C_MODE['prepare']['role'],[M4C_ENTRY,'prepare']);
    $base=m4c_entry_read($evidence.'ms4-customer-prepare.json');
    m4c_expect($base['schema']===1&&$base['scope']===M4C_MODE['prepare']['scope']&&$base['status']===M4C_MODE['prepare']['status']
        &&$base['run_id']===$run&&$base['source_sha']===$sha&&$base['freeze_sha256']===$freezeHash
        &&$base['container_id']===$prepared['id']&&$base['native_created_sha256']===hash_file('sha256',$evidence.'customer-prepare-created.json')
        &&$base['source_binding_sha256']===hash_file('sha256',$evidence.M4C_MODE['prepare']['binding'])
        &&$base['phase']==='final_source'&&$base['native_exit']===0&&$base['full_source_unchanged']===true,
        'M4C_H5_ORIGINAL_CUSTOMER_PREPARE_TERMINAL_AND_RESULT');
    $source['h5']=m4ch_entry_materials($source);m4cb_guard_readonly();return $source;
}
function m4ch_entry_run(): int
{
    m4cb_phase('source');$source=m4ch_entry_source();$GLOBALS['m4ch_entry_source']=$source;
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4c_terminate_requested']=true;});
    $business=m4ch_prepare($source);m4c_expect(empty($GLOBALS['m4c_terminate_requested']),'M4C_H5_PREPARE_NOT_INTERRUPTED');
    m4cb_phase('final_source');m4cb_guard_readonly();m4ch_entry_full_source($source);
    m4c_expect(m4ch_entry_materials($source)===$source['h5'],'M4C_H5_ALL_BOUND_PUBLIC_MATERIALS_UNCHANGED');
    $result=['schema'=>1,'scope'=>$source['rule']['scope'],'status'=>$source['rule']['status'],'run_id'=>$source['run_id'],
        'source_sha'=>$source['source_sha'],'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],
        'native_created_sha256'=>$source['native_created_sha256'],'source_binding_sha256'=>$source['source_binding_sha256'],
        'environment_sha256'=>$source['environment_sha256'],'public_copy_sha256'=>$source['h5']['public_copy_sha256'],
        'public_source_sha256'=>$source['h5']['public_source_sha256'],'phase'=>'final_source','native_exit'=>0,
        'full_source_unchanged'=>true,'public_materials_unchanged'=>true,'business'=>$business,
        'HTTP_executed'=>false,'credential_login_authenticated'=>false,'order_creation_executed'=>false,'HTTP_DOM'=>'NOT RUN',
        'assertions'=>$GLOBALS['m4c_assertions']??0,'assertions_by_phase'=>$GLOBALS['m4c_assertions_by_phase']??[]];
    m4c_expect(empty($GLOBALS['m4c_terminate_requested']),'M4C_H5_PREPARE_NOT_INTERRUPTED');
    m4c_entry_publish('/run/audit-http-control/prepare-result.json',$result);
    m4c_expect(empty($GLOBALS['m4c_terminate_requested']),'M4C_H5_PREPARE_NOT_INTERRUPTED');
    echo json_encode(['status'=>$source['rule']['status'],'scope'=>$source['rule']['scope'],'phase'=>'final_source'],JSON_THROW_ON_ERROR)."\n";
    return 0;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    set_error_handler(static function(int$severity,string$message,string$file,int$line):bool{
        if((error_reporting()&$severity)===0)return false;
        $GLOBALS['m4c_warning']=['severity'=>$severity,'file'=>basename($file),'line'=>$line];
        $GLOBALS['m4c_failed_assertion']='M4C_PHP_WARNING';throw new RuntimeException('M4C_PHP_WARNING');});
    try{exit(m4ch_entry_run());}
    catch(Throwable$error){$source=$GLOBALS['m4ch_entry_source']??null;$code=$GLOBALS['m4c_failed_assertion']??'';
        $safe=['schema'=>1,'status'=>'FAIL_PRESERVED','scope'=>'NORMAL_CUSTOMER_H5_PREPARATION_ONLY',
            'phase'=>$GLOBALS['m4c_phase']??'source','class'=>get_class($error),
            'error_location'=>['file'=>basename($error->getFile()),'line'=>$error->getLine()],
            'code'=>is_string($code)&&preg_match('/\AM4C_[A-Z0-9_]+\z/D',$code)===1?$code:'M4C_UNCLASSIFIED',
            'native_exit'=>1,'HTTP_executed'=>false,'credential_login_authenticated'=>false,'order_creation_executed'=>false,'HTTP_DOM'=>'NOT RUN'];
        if(is_array($source)){foreach(['run_id','source_sha','container_id','freeze_sha256']as$key)$safe[$key]=$source[$key];
            if(isset($GLOBALS['m4c_warning']))$safe['warning']=$GLOBALS['m4c_warning'];
            try{m4c_entry_publish('/run/audit-http-control/prepare-result.json',$safe);}catch(Throwable$ignored){}}
        echo json_encode($safe,JSON_THROW_ON_ERROR)."\n";exit(1);}
}
