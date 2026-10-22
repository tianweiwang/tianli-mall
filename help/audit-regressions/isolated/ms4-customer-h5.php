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
    m4ch_ms5_prerequisites($source,'prepare');
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
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','h5_source_files','h5_materials','public_copy','prerequisites_sha256']);
    m4c_expect($binding['schema']===1&&$binding['scope']==='MS4_CUSTOMER_H5_PREPARE_MS5_SOURCE'&&$binding['run_id']===$run
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
    $values=m4c_entry_environment($binding,$context,M4CH_PROOF,'MS4_CUSTOMER_H5_MS5_REAL_ENVIRONMENT');
    $source=$binding+['mode'=>'prepare','rule'=>['role'=>M4CH_ROLE,'proof'=>M4CH_PROOF,'scope'=>'NORMAL_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY',
        'status'=>'VERIFIED_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'],'environment'=>$values,
        'self_operated_mer_id'=>(int)$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'context'=>$context,'freeze'=>$freeze,
        'source_binding_sha256'=>hash_file('sha256',$bindingPath),'environment_sha256'=>hash_file('sha256',$environmentPath)];
    m4ch_entry_full_source($source);
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
const M4CH_HTTP_ROLE='audit-ms4-customer-h5';
const M4CH_HTTP_PROOF='customer-h5-serve';
const M4CH_HTTP_CONTROL='/run/audit-http-control';
const M4CH_HTTP_IDENTITIES=['run_id','source_sha','container_id','freeze_sha256','epoch','step_nonce','not_before','deadline'];
const M4CH_HTTP_MODES=['runtime','actor-meta','supervisor','write','stage','finish','audit'];
const M4CH_HTTP_ARTIFACTS=['H5-README.md','compose.h5.yml','h5-derivation.json','ms4-h5-host.py',
    'ms4-h5-materials.py','ms4-h5-bootstrap.php','ms4-h5-public-copy.php','ms4-h5-nginx.conf','ms4-order.png',
    'inputs/language.json','inputs/theme.json','manifests/public-source.json','manifests/h5-dist.json',
    'manifests/inputs.json','h5-materials-result.json','browser-v1/customer-network-observer.mjs',
    'browser-v1/ms4-customer-private-bridge.mjs','browser-v1/ms4-customer-browser.mjs',
    'normal-close-customer-h5.py','verify-zero-customer-h5.py'];
const M4CH_HTTP_ORIGINAL_ARTIFACTS=['README.md','compose.yml','ms4-host.py','ms4-initialize.php',
    'seed-source-inverse.json','interface-v1.json','source-derivation.json'];
const M4CH_HTTP_PREPARED_IDENTITIES=['schema','run_id','source_sha','freeze_sha256','container_id',
    'native_created_sha256','source_binding_sha256','public_copy_sha256','public_source_sha256','runtime_origin',
    'input_bindings','environment','self_operated_mer_id','base_fixture_sha256','base_prepare_receipt_sha256',
    'base_prepare_container_id','base_prepare_native_created_sha256','base_prepare_source_binding_sha256'];

/** The HTTP role has a different source contract; the original preparation functions above are unchanged. */
function m4ch_http_directory(string $path): array
{
    clearstatcache(true,$path);$stat=lstat($path);
    m4c_expect(is_array($stat)&&is_dir($path)&&!is_link($path)&&realpath($path)===$path
        &&$stat['mode']===0040700&&$stat['uid']===posix_geteuid(),'M4C_H5_HTTP_PRIVATE_DIRECTORY');
    return array_intersect_key($stat,array_flip(['dev','ino','mode','uid','gid']));
}
function m4ch_http_meta(string $path): array
{
    m4c_expect(str_starts_with($path,M4CH_HTTP_CONTROL.'/')||$path==='/run/audit-http-browser/customer-actors.json',
        'M4C_H5_HTTP_FIXED_PRIVATE_ROOT');
    m4c_expect(!str_contains($path,'/../')&&!str_contains($path,'\\'),'M4C_H5_HTTP_PRIVATE_CANONICAL_PATH');
    m4ch_http_directory(dirname($path));clearstatcache(true,$path);$before=lstat($path);
    m4c_expect(is_array($before)&&is_file($path)&&!is_link($path)&&realpath($path)===$path
        &&$before['mode']===0100600&&$before['uid']===posix_geteuid(),'M4C_H5_HTTP_PRIVATE_FILE');
    $hash=hash_file('sha256',$path);clearstatcache(true,$path);$after=lstat($path);
    $keys=array_flip(['dev','ino','mode','uid','gid','size','mtime','ctime']);
    m4c_expect(is_string($hash)&&is_array($after)&&array_intersect_key($before,$keys)===array_intersect_key($after,$keys),
        'M4C_H5_HTTP_PRIVATE_META_UNCHANGED');
    return ['bytes'=>$before['size'],'sha256'=>$hash,'dev'=>$before['dev'],'ino'=>$before['ino'],'mode'=>$before['mode'],'uid'=>$before['uid']];
}
function m4ch_http_private_read(string $path,int $limit=2097152): array
{
    $before=m4ch_http_meta($path);m4c_expect($before['bytes']>0&&$before['bytes']<=$limit,'M4C_H5_HTTP_PRIVATE_SIZE');
    $raw=file_get_contents($path);m4c_expect(is_string($raw)&&strlen($raw)===$before['bytes']
        &&hash('sha256',$raw)===$before['sha256'],'M4C_H5_HTTP_PRIVATE_READ');
    $value=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
    m4c_expect(is_array($value)&&!array_is_list($value)&&m4ch_http_meta($path)===$before,'M4C_H5_HTTP_PRIVATE_OBJECT_UNCHANGED');
    return $value;
}
/** Atomic, create-only publication. There is no filename argument in the ingress wire. */
function m4ch_http_publish(string $path,array|string $value): array
{
    m4c_expect(str_starts_with($path,M4CH_HTTP_CONTROL.'/')&&!str_contains($path,'/../')&&!str_contains($path,'\\'),
        'M4C_H5_HTTP_PUBLISH_PRIVATE_ROOT');m4ch_http_directory(dirname($path));
    m4c_expect(!file_exists($path)&&!is_link($path),'M4C_H5_HTTP_CREATE_ONLY');
    $bytes=is_array($value)?m4cb_json($value):$value;
    m4c_expect(strlen($bytes)>0&&strlen($bytes)<=2097152,'M4C_H5_HTTP_PUBLISH_BOUNDED');
    $temporary=$path.'.tmp-'.bin2hex(random_bytes(12));$stream=fopen($temporary,'xb');
    m4c_expect(is_resource($stream)&&chmod($temporary,0600),'M4C_H5_HTTP_PRIVATE_OPEN');
    try{$offset=0;while($offset<strlen($bytes)){$written=fwrite($stream,substr($bytes,$offset));
        m4c_expect(is_int($written)&&$written>0,'M4C_H5_HTTP_COMPLETE_WRITE');$offset+=$written;}
        m4c_expect(fflush($stream)&&fsync($stream),'M4C_H5_HTTP_PRIVATE_FLUSH');}
    finally{fclose($stream);}
    $meta=m4ch_http_meta($temporary);m4c_expect($meta['bytes']===strlen($bytes)&&$meta['sha256']===hash('sha256',$bytes),
        'M4C_H5_HTTP_PRIVATE_FULL_BYTES');
    m4c_expect(link($temporary,$path)&&m4ch_http_meta($path)===$meta,'M4C_H5_HTTP_ATOMIC_CREATE_ONLY');
    m4c_expect(m4ch_http_meta($temporary)===$meta&&unlink($temporary),'M4C_H5_HTTP_REMOVE_ONLY_OWN_TEMPORARY');return $meta;
}
function m4ch_http_rows(array $rows,?array $expected=null): array
{
    m4c_expect(array_is_list($rows)&&$rows!==[],'M4C_H5_HTTP_SOURCE_DESCRIPTOR_LIST');$paths=[];
    foreach($rows as$row){m4c_expect(is_array($row),'M4C_H5_HTTP_SOURCE_DESCRIPTOR_OBJECT');
        m4c_entry_keys($row,['path','bytes','sha256']);m4c_expect(is_string($row['path'])&&is_int($row['bytes'])&&$row['bytes']>=0
            &&is_string($row['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$row['sha256'])===1,'M4C_H5_HTTP_SOURCE_DESCRIPTOR');
        m4ch_entry_relative($row['path']);$paths[]=$row['path'];}
    m4c_expect(count($paths)===count(array_unique($paths,SORT_STRING))&&($expected===null||$paths===$expected),
        'M4C_H5_HTTP_EXACT_SOURCE_PATHS');return $rows;
}
function m4ch_http_bound_files(string $root,array $rows): void
{
    foreach($rows as$row){$path=$root.'/'.$row['path'];m4c_expect(is_file($path)&&!is_link($path)&&realpath($path)===$path
        &&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],'M4C_H5_HTTP_BOUND_SOURCE_BYTES');}
}
function m4ch_http_environment(array $values): void
{
    m4c_entry_keys($values,['APP_ENV','PHP_MARKETPLACE_ENABLED','PHP_MARKETPLACE_TRADE_ENABLED',
        'PHP_MARKETPLACE_PAYMENT_TEST_ENABLED','PHP_MARKETPLACE_SELF_OPERATED_MER_ID','PHP_CACHE_DRIVER']);
    m4c_expect($values['APP_ENV']==='testing'&&$values['PHP_MARKETPLACE_ENABLED']==='true'
        &&$values['PHP_MARKETPLACE_TRADE_ENABLED']==='true'&&$values['PHP_MARKETPLACE_PAYMENT_TEST_ENABLED']==='true'
        &&$values['PHP_CACHE_DRIVER']==='redis'
        &&is_string($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])
        &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'])===1
        &&bccomp($values['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'],'4294967295',0)<=0,'M4C_H5_HTTP_ENABLED_ENVIRONMENT');
    foreach($values as$key=>$value)m4c_expect(getenv($key)===$value,'M4C_H5_HTTP_ACTUAL_PROCESS_ENVIRONMENT');
}
function m4ch_http_same_environment(array $left,array $right): bool
{
    ksort($left,SORT_STRING);ksort($right,SORT_STRING);return $left===$right;
}
function m4ch_http_networks(array $source,array $manifest): array
{
    $rows=[];foreach(['backend','frontend']as$kind){
        $value=m4ch_entry_json($source['network_receipts'][$kind],'/run/audit/evidence/http-'.$kind.'-network-created.json');
        m4c_entry_keys($value,['schema','scope','run_id','source_sha','freeze_sha256','network']);
        m4c_expect($value['schema']===1&&$value['scope']==='MS4_HTTP_'.strtoupper($kind).'_NATIVE_IDENTITY_ONLY'
            &&$value['run_id']===$source['run_id']&&$value['source_sha']===$source['source_sha']
            &&$value['freeze_sha256']===$source['freeze_sha256'],'M4C_H5_HTTP_NETWORK_SOURCE_IDENTITY');
        $network=$value['network'];m4c_entry_keys($network,['Id','Name','Driver','Scope','Internal','EnableIPv6','Options','Labels','Containers']);
        m4c_expect(is_string($network['Id'])&&preg_match('/\A[a-f0-9]{64}\z/D',$network['Id'])===1
            &&$network['Driver']==='bridge'&&$network['Scope']==='local'&&$network['EnableIPv6']===false
            &&is_array($network['Options'])&&is_array($network['Labels'])&&is_array($network['Containers']),
            'M4C_H5_HTTP_REAL_NETWORK_NATIVE_FACTS');
        $identity=array_diff_key($network,['Containers'=>true]);
        if($kind==='backend')m4c_expect(m4ch_http_same(array_intersect_key($network,array_flip(['Id','Name','Internal','Labels'])),
            $source['context']['initial_network'])&&$network['Internal']===true&&$network['Id']===$source['context']['network_id']
            &&$manifest['network']===['id'=>$network['Id'],'name'=>$network['Name']],'M4C_H5_HTTP_ORIGINAL_INTERNAL_NETWORK');
        else{m4c_expect($network['Name']===$source['context']['project'].'_frontend'&&$network['Internal']===false
            &&m4ch_http_same_environment($network['Options'],['com.docker.network.enable_ipv4'=>'true','com.docker.network.bridge.enable_ip_masquerade'=>'false'])
            &&m4ch_http_same_environment($network['Labels'],['tianli.audit'=>'1','tianli.audit.run'=>$source['run_id'],
                'com.docker.compose.project'=>$source['context']['project'],'com.docker.compose.network'=>'frontend'])
            &&$network['Containers']===[]&&m4ch_http_same($manifest['frontend_network'],$identity),'M4C_H5_HTTP_FRONTEND_NATIVE_IDENTITY');}
        $rows[$kind]=$identity;
    }
    m4c_expect($rows['backend']['Id']!==$rows['frontend']['Id'],'M4C_H5_HTTP_DISTINCT_NETWORKS');return $rows;
}
/** Metadata-only public validation for small ingress; the full branch additionally scans both actual trees. */
function m4ch_http_materials(array $source,bool $full): array
{
    if($full)return m4ch_entry_materials($source);
    $materials=$source['h5_materials'];m4c_entry_keys($materials,['runtime_origin','public_source','dist','inputs','fixture_png']);
    m4c_expect(is_string($materials['runtime_origin'])
        &&preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~D',$materials['runtime_origin'],$port)===1
        &&(int)$port[1]<=65535,'M4C_H5_HTTP_FIXED_ORIGIN');$trees=[];
    foreach(M4CH_MANIFESTS as$name=>$path)$trees[$name]=m4ch_entry_json($materials[$name],$path);
    foreach(['public_source','dist']as$name){m4c_entry_keys($trees[$name],['schema','files','directories']);
        m4c_expect($trees[$name]['schema']===1,'M4C_H5_HTTP_MATERIAL_MANIFEST_SCHEMA');
        m4ch_entry_rows($trees[$name]['files']);m4ch_entry_directories($trees[$name]['directories']);}
    m4c_entry_keys($trees['inputs'],['schema','language','theme']);m4c_expect($trees['inputs']['schema']===1,'M4C_H5_HTTP_INPUT_SCHEMA');
    foreach(M4CH_INPUTS as$name=>$descriptor){m4c_expect($trees['inputs'][$name]===$descriptor,'M4C_H5_HTTP_EXACT_APPROVED_INPUT');
        m4ch_entry_json($descriptor,$descriptor['path']);}
    $copy=m4ch_entry_json($source['public_copy'],'/run/audit/evidence/customer-h5-public-copy.json');
    m4c_entry_keys($copy,['schema','scope','run_id','source_sha','freeze_sha256','source_identity','read_only_source',
        'source_count','h5_count','public_count','files','directories','inputs_unchanged','closed_set_verified','marker_sha256','png_sha256','permissions']);
    m4c_expect($copy['schema']===1&&$copy['scope']==='MS4_CURRENT_H5_PUBLIC_SNAPSHOT_ONLY'
        &&$copy['run_id']===$source['run_id']&&$copy['source_sha']===$source['source_sha']&&$copy['freeze_sha256']===$source['freeze_sha256']
        &&$copy['read_only_source']===true&&$copy['inputs_unchanged']===true&&$copy['closed_set_verified']===true,
        'M4C_H5_HTTP_PUBLIC_COPY_SOURCE');
    m4c_entry_keys($copy['source_identity'],['proof_name','role','command','container_id','native_created_sha256']);
    $command=['/run/ms4-prep/ms4-h5-public-copy.php','copy'];$created=m4c_entry_terminal($source,'customer-h5-public-copy',
        'audit-ms4-customer-h5-public-copy',$command);
    m4c_expect(m4ch_http_same($copy['source_identity'],['proof_name'=>'customer-h5-public-copy','role'=>'audit-ms4-customer-h5-public-copy',
        'command'=>$command,'container_id'=>$created['id'],
        'native_created_sha256'=>hash_file('sha256','/run/audit/evidence/customer-h5-public-copy-created.json')]),
        'M4C_H5_HTTP_ACTUAL_PUBLIC_COPY_IDENTITY');
    $expected=[];$directories=[];foreach($trees['public_source']['files']as$row)$expected[$row['path']]=$row;
    foreach($trees['public_source']['directories']as$path)$directories[$path]=true;
    foreach($trees['dist']['files']as$row){$path='__ms4_assets/h5/'.$row['path'];m4c_expect(!isset($expected[$path]),'M4C_H5_HTTP_NO_ASSET_COLLISION');
        $expected[$path]=['path'=>$path,'bytes'=>$row['bytes'],'sha256'=>$row['sha256']];}
    foreach($trees['dist']['directories']as$path)$directories['__ms4_assets/h5/'.$path]=true;
    $png=$materials['fixture_png'];m4c_entry_keys($png,['path','bytes','sha256']);
    m4c_expect($png['path']==='/run/ms4-prep/ms4-order.png'&&is_int($png['bytes'])&&$png['bytes']>0&&$png['bytes']<=2097152
        &&is_file($png['path'])&&!is_link($png['path'])&&filesize($png['path'])===$png['bytes']
        &&hash_file('sha256',$png['path'])===$png['sha256'],'M4C_H5_HTTP_FROZEN_REAL_PNG');
    $marker='# AUDIT-RUN '.$source['run_id']."\n";$expected['install.lock']=['path'=>'install.lock','bytes'=>strlen($marker),'sha256'=>hash('sha256',$marker)];
    $expected['__ms4_fixture__/product.png']=['path'=>'__ms4_fixture__/product.png','bytes'=>$png['bytes'],'sha256'=>$png['sha256']];
    foreach(array_keys($expected)as$path){$parent=dirname($path);while($parent!=='.'){$directories[$parent]=true;$parent=dirname($parent);}}
    ksort($expected,SORT_STRING);$directories=array_keys($directories);sort($directories,SORT_STRING);
    m4c_expect($copy['files']===array_values($expected)&&$copy['directories']===$directories
        &&$copy['source_count']===count($trees['public_source']['files'])&&$copy['h5_count']===count($trees['dist']['files'])
        &&$copy['public_count']===count($expected)&&$copy['marker_sha256']===hash('sha256',$marker)&&$copy['png_sha256']===$png['sha256'],
        'M4C_H5_HTTP_PUBLIC_COMPLETE_DECLARED_CLOSURE');
    m4c_entry_keys($copy['permissions'],['publisher_uid','directory_mode','file_mode','verified']);
    m4c_expect(is_int($copy['permissions']['publisher_uid'])&&$copy['permissions']['publisher_uid']>=0
        &&$copy['permissions']['directory_mode']==='0755'&&$copy['permissions']['file_mode']==='0644'&&$copy['permissions']['verified']===true,
        'M4C_H5_HTTP_PUBLIC_DECLARED_PERMISSIONS');
    foreach(['install.lock','__ms4_fixture__/product.png']as$relative){$path='/workspace/crmeb/public/'.$relative;$row=$expected[$relative];
        m4c_expect(is_file($path)&&!is_link($path)&&filesize($path)===$row['bytes']&&hash_file('sha256',$path)===$row['sha256'],
            'M4C_H5_HTTP_ACTUAL_PUBLIC_MARKER_PNG');}
    return ['runtime_origin'=>$materials['runtime_origin'],'public_copy_sha256'=>$source['public_copy']['sha256'],
        'public_source_sha256'=>$materials['public_source']['sha256'],'inputs'=>M4CH_INPUTS];
}
function m4ch_http_included(array $source,bool $full): void
{
    $original=m4ch_http_rows($source['source_files']);$additional=m4ch_http_rows($source['h5_source_files'],M4CH_SOURCE_FILES);
    m4ch_http_rows($source['artifact_files'],M4CH_HTTP_ORIGINAL_ARTIFACTS);
    m4ch_http_rows($source['h5_artifact_files'],M4CH_HTTP_ARTIFACTS);$bound=[];
    foreach(['/source','/workspace']as$root)foreach([...$original,...$additional]as$row)$bound[$root.'/'.$row['path']]=$row;
    foreach([...$source['artifact_files'],...$source['h5_artifact_files']]as$row)$bound['/run/ms4-prep/'.$row['path']]=$row;
    if($full){m4c_entry_full_source($source);foreach(['/source','/workspace']as$root)m4ch_http_bound_files($root,$additional);
        m4ch_http_bound_files('/run/ms4-prep',[...$source['artifact_files'],...$source['h5_artifact_files']]);}
    foreach(get_included_files()as$included){$real=realpath($included);
        m4c_expect(is_string($real)&&isset($bound[$real]),'M4C_H5_HTTP_INCLUDED_IN_FROZEN_SOURCE');
        $row=$bound[$real];m4c_expect(is_file($real)&&!is_link($real)&&filesize($real)===$row['bytes']
            &&hash_file('sha256',$real)===$row['sha256'],'M4C_H5_HTTP_ACTUAL_INCLUDED_BYTES');}
}
function m4ch_http_preparation(array $source): array
{
    $evidence='/run/audit/evidence/';$run=$source['run_id'];$sha=$source['source_sha'];$freezeHash=$source['freeze_sha256'];
    $prepared=m4c_entry_terminal($source,M4CH_PROOF,M4CH_ROLE,[M4CH_ENTRY,'prepare']);
    $result=m4ch_entry_json($source['prepare_result'],M4CH_HTTP_CONTROL.'/prepare-result.json');
    m4ch_http_meta(M4CH_HTTP_CONTROL.'/prepare-result.json');
    $binding=m4c_entry_read($evidence.'ms4-customer-h5-prepare-source-binding.json');
    $environment=m4c_entry_read($evidence.'customer-h5-prepare-environment.json');
    m4c_expect($result['schema']===1&&$result['scope']==='NORMAL_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
        &&$result['status']==='VERIFIED_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'&&$result['run_id']===$source['run_id']
        &&$result['source_sha']===$source['source_sha']&&$result['container_id']===$prepared['id']
        &&$result['freeze_sha256']===$source['freeze_sha256']&&$result['native_created_sha256']===hash_file('sha256',$evidence.M4CH_PROOF.'-created.json')
        &&$result['source_binding_sha256']===hash_file('sha256',$evidence.'ms4-customer-h5-prepare-source-binding.json')
        &&$result['environment_sha256']===hash_file('sha256',$evidence.'customer-h5-prepare-environment.json')
        &&$result['public_copy_sha256']===$source['h5']['public_copy_sha256']&&$result['public_source_sha256']===$source['h5']['public_source_sha256']
        &&$result['phase']==='final_source'&&$result['native_exit']===0&&$result['full_source_unchanged']===true
        &&$result['public_materials_unchanged']===true&&$result['HTTP_executed']===false
        &&$result['credential_login_authenticated']===false&&$result['order_creation_executed']===false&&$result['HTTP_DOM']==='NOT RUN'
        &&$binding['container_id']===$prepared['id']&&$binding['run_id']===$source['run_id']&&$binding['source_sha']===$source['source_sha']
        &&$binding['freeze_sha256']===$source['freeze_sha256']&&$binding['source_files']===$source['source_files']
        &&$binding['h5_source_files']===$source['h5_source_files']&&$binding['h5_materials']===$source['h5_materials']
        &&$binding['public_copy']===$source['public_copy']&&m4ch_http_same_environment($environment['environment'],$source['environment']),
        'M4C_H5_HTTP_ORIGINAL_H5_PREPARE_TERMINAL_RESULT_BINDING');
    m4c_expect($result['business']['fixture_counts']===['customers'=>1,'merchants'=>2,'products'=>3,'skus'=>4,'cart_rows'=>0,'orders'=>0]
        &&$result['business']['business_table_count']===27&&$result['business']['auxiliary_table_count']===7
        &&$result['business']['base_fixture_preserved']===true&&$result['business']['production_configuration_published_and_read_back']===true
        &&$result['business']['theme_service_saved_enabled_and_read_back']===true&&$result['business']['language_native_ids_and_full_package_verified']===true,
        'M4C_H5_HTTP_REAL_PREPARATION_ONLY_COUNTS');
    $files=m4c_entry_terminal($source,'customer-h5-files','audit-ms4-customer-h5-files',['/run/ms4-prep/ms4-h5-bootstrap.php','files-bootstrap']);
    $installed=m4ch_entry_json($source['files_result'],M4CH_HTTP_CONTROL.'/files-result.json');m4ch_http_meta(M4CH_HTTP_CONTROL.'/files-result.json');
    $filesBinding=m4c_entry_read($evidence.'customer-h5-files-source-binding.json');$business=$installed['business'];$installation=$business['installation'];
    m4c_expect($installed['schema']===1&&$installed['scope']==='CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
        &&$installed['status']==='VERIFIED_CUSTOMER_H5_WITH_MS5_FILES_BOOTSTRAP_ONLY'&&$installed['phase']==='files-bootstrap'
        &&$installed['proof_name']==='customer-h5-files'&&$installed['run_id']===$source['run_id']&&$installed['source_sha']===$source['source_sha']
        &&$installed['container_id']===$files['id']&&$installed['freeze_sha256']===$source['freeze_sha256']
        &&$installed['native_created_sha256']===hash_file('sha256',$evidence.'customer-h5-files-created.json')
        &&$installed['source_binding_sha256']===hash_file('sha256',$evidence.'customer-h5-files-source-binding.json')
        &&$installed['native_exit']===0&&$installed['full_source_unchanged']===true&&$installed['HTTP_DOM']==='NOT RUN'
        &&$filesBinding['public_copy']===$source['public_copy']&&$filesBinding['prepare_result']===$source['prepare_result']
        &&$business['business_tables']===27&&$business['auxiliary_tables']===7&&$business['complete_native_rows_unchanged']===true
        &&$business['sealed_baseline_and_base_fixture_unchanged']===true&&$business['DML_prepare_actual_terminal_zero_verified']===true
        &&$business['public_materials_unchanged']===true&&$business['HTTP_executed']===false
        &&$business['credential_login_authenticated']===false&&$business['order_creation_executed']===false
        &&$installation['account']==='audit_migrator'&&is_int($installation['checksum_rows'])&&$installation['checksum_rows']>0
        &&$installation['source_matches']===true&&$installation['version_marker_matches']===true
        &&$installation['application_account']==='audit_app'&&$installation['application_dml_only']===true,
        'M4C_H5_HTTP_FINAL_DEDICATED_FILES_TERMINAL_AND_NATIVE_34_BASELINE');
    return ['container_id'=>$prepared['id'],'native_created_sha256'=>$result['native_created_sha256'],
        'source_binding_sha256'=>$result['source_binding_sha256'],'prepare_result_sha256'=>$source['prepare_result']['sha256']];
}
function m4ch_http_source(bool $full): array
{
    $nativeArgs=$_SERVER['argv']??[];m4c_expect(($nativeArgs===[($_SERVER['SCRIPT_FILENAME']??''),'serve'])
        ||(count($nativeArgs)===3&&$nativeArgs[0]===($_SERVER['SCRIPT_FILENAME']??null)&&$nativeArgs[1]==='ingress'
            &&in_array($nativeArgs[2],M4CH_HTTP_MODES,true)),'M4C_H5_HTTP_ACTUAL_EXACT_NATIVE_ARGV');
    m4c_expect(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE>=8
        &&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__&&getcwd()==='/workspace'
        &&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit'&&posix_geteuid()===0,'M4C_H5_HTTP_NATIVE_ENTRY');
    $evidence='/run/audit/evidence/';$context=m4c_entry_read('/run/audit/actual-run-context.json');
    $freeze=m4c_entry_read('/run/audit/preparation-source-binding.json');$manifest=audit_manifest();
    $bindingPath=$evidence.'ms4-customer-h5-serve-source-binding.json';$binding=m4c_entry_read($bindingPath);
    m4c_entry_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files','h5_source_files',
        'artifact_files','h5_artifact_files','h5_materials','public_copy','prepare_result','files_result','registration_result',
        'environment','network_receipts','stop_signal','manifest','registration_inputs','prerequisites_sha256']);
    $run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');$freezeHash=hash_file('sha256','/run/audit/preparation-source-binding.json');
    m4c_expect(getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'&&getenv('MS4_PROOF_NAME')===M4CH_HTTP_PROOF
        &&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run)===1&&preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha)===1
        &&$context['run_id']===$run&&$context['source_sha']===$sha&&$context['project']==='tianli-audit-'.$run
        &&getenv('AUDIT_PROJECT')===$context['project']&&$context['freeze_sha256']===$freezeHash
        &&$freeze['source_sha']===$sha&&$freeze['status']==='CLEAN_SOURCE_FROZEN_ONLY','M4C_H5_HTTP_ORIGINAL_RUN_AND_FREEZE');
    $extra=M4C_EXTRA;sort($extra,SORT_STRING);m4c_expect($freeze['customer_source_roots']===M4C_ROOTS
        &&$freeze['customer_source_extra']===$extra&&$binding['source_files']===$freeze['customer_source_files']
        &&$binding['h5_source_files']===$freeze['h5_source_files']&&$binding['artifact_files']===$freeze['artifact_files']
        &&$binding['h5_artifact_files']===$freeze['h5_artifact_files']&&$binding['h5_materials']===$freeze['h5_materials'],
        'M4C_H5_HTTP_ORIGINAL_SOURCE_CLOSURE_AND_SEPARATE_H5_SET');
    $created=m4c_entry_read($evidence.M4CH_HTTP_PROOF.'-created.json');$cid=$created['id'];
    m4c_expect(is_string($cid)&&preg_match('/\A[a-f0-9]{64}\z/D',$cid)===1&&gethostname()===substr($cid,0,12)
        &&$binding['schema']===1&&$binding['scope']==='MS4_CUSTOMER_H5_HTTP_MS5_SOURCE'&&$binding['run_id']===$run
        &&$binding['source_sha']===$sha&&$binding['container_id']===$cid&&$binding['freeze_sha256']===$freezeHash
        &&$binding['native_created_sha256']===hash_file('sha256',$evidence.M4CH_HTTP_PROOF.'-created.json')
        &&$binding['requested_repo_source']===$context['repo_dir']&&$binding['requested_run_source']===$context['run_dir']
        &&$binding['requested_prep_source']===$context['prep_dir']&&$binding['entry_sha256']===hash_file('sha256',__FILE__),
        'M4C_H5_HTTP_REAL_CREATED_SOURCE_BINDING');
    $source=$binding+['context'=>$context,'freeze'=>$freeze,'mode'=>'serve','source_binding_sha256'=>hash_file('sha256',$bindingPath)];
    $boundManifest=m4ch_entry_json($binding['manifest'],'/run/audit/manifest.json');
    m4c_expect($manifest===$boundManifest&&$manifest['run_id']===$run&&$manifest['project']===$context['project']
        &&$manifest['commit_sha']===$sha&&$manifest['isolation']==='ISO-FULL'&&$manifest['guard_mode']==='read-only'
        &&$manifest['network_internal']===true,'M4C_H5_HTTP_FORMAL_MANIFEST_BINDING');
    $roles=array_keys($manifest['services']);sort($roles,SORT_STRING);
    m4c_expect($roles===['audit-ms4-customer-h5','audit-mysql','audit-redis','audit-ui']
        &&$manifest['services'][M4CH_HTTP_ROLE]['id']===$cid&&$manifest['services'][M4CH_HTTP_ROLE]['image_id']===M4C_IMAGE
        &&count($manifest['volumes'])===10&&count(array_unique($manifest['volumes'],SORT_STRING))===10,
        'M4C_H5_HTTP_ONLY_FOUR_FINAL_ROLES_TEN_VOLUMES');
    $suffixes=['app-secrets','bootstrap-secrets','fixture-secrets','mysql-data','redis-data','redis-secrets',
        'http-runtime','http-control','http-browser-secrets','http-public'];$wanted=[];
    foreach($suffixes as$suffix)$wanted[]=$context['project'].'_'.$suffix;sort($wanted,SORT_STRING);$actual=$manifest['volumes'];sort($actual,SORT_STRING);
    $identityNames=array_keys($manifest['volume_identities']);sort($identityNames,SORT_STRING);
    m4c_expect($actual===$wanted&&$identityNames===$wanted,'M4C_H5_HTTP_EXACT_TEN_REGISTERED_VOLUME_IDENTITIES');
    $environment=m4ch_entry_json($binding['environment'],$evidence.'customer-h5-serve-environment.json');
    $source['environment']=m4c_entry_environment($binding,$context,M4CH_HTTP_PROOF,'MS4_CUSTOMER_H5_MS5_REAL_ENVIRONMENT');
    m4ch_http_environment($source['environment']);
    $source['environment_sha256']=$binding['environment']['sha256'];
    $source['self_operated_mer_id']=(int)$source['environment']['PHP_MARKETPLACE_SELF_OPERATED_MER_ID'];
    m4c_entry_keys($source['network_receipts'],['backend','frontend']);$networks=m4ch_http_networks($source,$manifest);$backend=$networks['backend'];
    m4c_expect($created['run_id']===$run&&$created['source_sha']===$sha&&$created['proof_name']===M4CH_HTTP_PROOF
        &&$created['image']===M4C_IMAGE&&$created['hostname']===gethostname()&&$created['status']==='created'
        &&$created['pid']===0&&$created['restart_count']===0&&$created['entrypoint']===['php']&&$created['command']===[M4CH_ENTRY,'serve']
        &&$created['workdir']==='/workspace'&&$created['read_only']===true&&$created['privileged']===false
        &&in_array($created['user'],['','0','0:0','root'],true)&&$created['stop_signal']==='SIGTERM'
        &&$created['cap_drop']===['ALL']&&in_array($created['security_options'],[['no-new-privileges'],['no-new-privileges:true']],true)
        &&$created['network_mode']===$backend['Name']&&array_keys($created['networks'])===[$backend['Name']]
        &&in_array($created['networks'][$backend['Name']]['NetworkID'],['',$backend['Id']],true)
        &&in_array($created['port_bindings'],[null,[]],true),'M4C_H5_HTTP_ACTUAL_UNPRIVILEGED_CREATED_ROLE');
    foreach($created['ports']??[]as$value)m4c_expect(in_array($value,[null,[]],true),'M4C_H5_HTTP_NO_BACKEND_HOST_PORT');
    m4c_expect(!in_array('audit-app',$created['networks'][$backend['Name']]['Aliases']??[],true),'M4C_H5_HTTP_NO_LEGACY_APP_ALIAS');
    foreach(['tianli.audit'=>'1','tianli.audit.run'=>$run,'com.docker.compose.project'=>$context['project'],
        'com.docker.compose.service'=>M4CH_HTTP_ROLE]as$key=>$value)m4c_expect($created['labels'][$key]===$value,'M4C_H5_HTTP_NATIVE_LABELS');
    $binds=['/workspace'=>[$context['repo_dir'],false],'/source'=>[$context['repo_dir'],false],
        '/run/ms4-prep'=>[$context['prep_dir'],false],'/run/audit'=>[$context['run_dir'],false]];
    $volumes=['/run/audit-app'=>['app-secrets',false],'/run/audit-fixtures'=>['fixture-secrets',false],
        '/workspace/crmeb/public'=>['http-public',false],'/workspace/crmeb/runtime'=>['http-runtime',true],
        M4CH_HTTP_CONTROL=>['http-control',true],'/run/audit-http-browser'=>['http-browser-secrets',false]];
    $mounts=[];$tmp=[];foreach($created['mounts']as$mount){if($mount['Type']==='tmpfs')$tmp[]=$mount;else$mounts[]=$mount;}
    m4c_expect(count($mounts)===10&&count($tmp)<=1,'M4C_H5_HTTP_CLOSED_NATIVE_MOUNT_SET');
    foreach($tmp as$mount)m4c_expect($mount['Destination']==='/tmp'&&$mount['RW']===true,'M4C_H5_HTTP_ONLY_TMPFS_TARGET');
    foreach(array_merge($binds,$volumes)as$target=>$expected){$matches=array_values(array_filter($mounts,static fn(array$row):bool=>$row['Destination']===$target));
        m4c_expect(count($matches)===1&&$matches[0]['RW']===$expected[1],'M4C_H5_HTTP_ONE_EXACT_MOUNT_ACCESS');$mount=$matches[0];
        if(isset($binds[$target]))m4c_expect($mount['Type']==='bind'&&audit_canonical_host_bind_source($mount['Source'])
            ===audit_canonical_host_bind_source($expected[0]),'M4C_H5_HTTP_REAL_HOST_BIND_SOURCE');
        else m4c_expect($mount['Type']==='volume'&&$mount['Name']===$context['project'].'_'.$expected[0],'M4C_H5_HTTP_OWN_VOLUME_SOURCE');}
    m4c_expect(array_keys($created['tmpfs'])===['/tmp']&&is_string($created['tmpfs']['/tmp'])
        &&in_array('rw',explode(',',$created['tmpfs']['/tmp']),true),'M4C_H5_HTTP_DECLARED_TMPFS');
    $tmpObserved=false;foreach(file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES)as$line){$parts=explode(' - ',$line,2);
        $left=explode(' ',$parts[0]);$right=explode(' ',$parts[1]??'');if(($left[4]??'')==='/tmp'&&($right[0]??'')==='tmpfs')$tmpObserved=true;}
    m4c_expect($tmpObserved&&!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/public/install.lock'),'M4C_H5_HTTP_REAL_TMPFS_NO_HOST_RUNTIME_CONFIGURATION');
    m4c_entry_keys($source['registration_inputs'],['containers','volumes','volume_identities']);
    foreach(['containers'=>'containers.jsonl','volumes'=>'volumes.txt','volume_identities'=>'volumes.jsonl']as$key=>$name){
        $descriptor=$source['registration_inputs'][$key];m4c_entry_keys($descriptor,['path','bytes','sha256']);
        m4c_expect($descriptor['path']==='/run/audit/'.$name&&is_int($descriptor['bytes'])&&$descriptor['bytes']>0
            &&is_file($descriptor['path'])&&!is_link($descriptor['path'])&&filesize($descriptor['path'])===$descriptor['bytes']
            &&hash_file('sha256',$descriptor['path'])===$descriptor['sha256'],'M4C_H5_HTTP_ORIGINAL_PREREGISTRATION_INPUT_BYTES');}
    $stop=m4ch_entry_json($source['stop_signal'],$evidence.'http-app-stop-signal.json');
    m4c_expect($stop['schema']===1&&$stop['scope']==='MS4_HTTP_APP_STOP_SIGNAL_NATIVE_ONLY'&&$stop['run_id']===$run
        &&$stop['source_sha']===$sha&&$stop['freeze_sha256']===$freezeHash&&$stop['container_id']===$cid
        &&$stop['native_created_sha256']===$binding['native_created_sha256']&&$stop['effective_stop_signal']==='SIGTERM'
        &&$stop['native_inspect']['exit_code']===0&&$stop['native_inspect']['stderr_bytes']===0
        &&$stop['native_inspect']['stderr_sha256']===hash('sha256','')&&$manifest['http_stop_signal_sha256']===$source['stop_signal']['sha256'],
        'M4C_H5_HTTP_REAL_STOP_SIGNAL');
    $register=m4c_entry_terminal($source,'register-customer-h5-serve','audit-ms4-customer-h5-register',
        ['/run/ms4-prep/ms4-h5-bootstrap.php','register-final']);
    $registered=m4ch_entry_json($source['registration_result'],$evidence.'register-customer-h5-serve-result.json');
    m4c_expect($registered['schema']===1&&$registered['scope']==='CUSTOMER_H5_BOOTSTRAP_WITH_MS5_PREREQUISITES_PREPARATION_ONLY'
        &&$registered['status']==='REGISTERED_CUSTOMER_H5_WITH_MS5_FINAL_RESOURCES_ONLY'&&$registered['phase']==='register-final'
        &&$registered['run_id']===$run&&$registered['source_sha']===$sha&&$registered['container_id']===$register['id']
        &&$registered['freeze_sha256']===$freezeHash&&$registered['native_exit']===0&&$registered['full_source_unchanged']===true
        &&$registered['native_created_sha256']===hash_file('sha256',$evidence.'register-customer-h5-serve-created.json')
        &&$registered['source_binding_sha256']===hash_file('sha256',$evidence.'register-customer-h5-serve-source-binding.json')
        &&$registered['business']['registered_roles']===$roles&&$registered['business']['input_inventory_excludes_transient_helper']===true
        &&$registered['business']['manifest_sha256']===$source['manifest']['sha256'],'M4C_H5_HTTP_REAL_FINAL_REGISTRATION_TERMINAL');
    m4ch_http_included($source,$full);$source['h5']=m4ch_http_materials($source,$full);
    m4ch_ms5_prerequisites($source,'serve');
    $source['prepare_identity']=m4ch_http_preparation($source);m4cb_guard_readonly();return $source;
}
function m4ch_http_identity(array $value,array $source,?array $runtime=null,bool $live=true): void
{
    m4c_expect(($value['schema']??null)===1,'M4C_H5_HTTP_FRAME_SCHEMA');
    foreach(['run_id','source_sha','container_id','freeze_sha256']as$key)m4c_expect(($value[$key]??null)===$source[$key],'M4C_H5_HTTP_FRAME_SOURCE');
    m4c_expect(is_string($value['epoch']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$value['epoch'])===1
        &&is_string($value['step_nonce']??null)&&preg_match('/\A[a-f0-9]{48}\z/D',$value['step_nonce'])===1
        &&is_int($value['not_before']??null)&&is_int($value['deadline']??null)&&$value['deadline']>=$value['not_before'],
        'M4C_H5_HTTP_FRAME_NONCE_AND_TIME');
    if($live)m4c_expect($value['not_before']<=time()&&time()<=$value['deadline'],'M4C_H5_HTTP_LIVE_FRAME');
    if($runtime!==null)m4c_expect($value['epoch']===$runtime['epoch']&&$value['not_before']>=$runtime['not_before']
        &&$value['deadline']===$runtime['deadline'],'M4C_H5_HTTP_SINGLE_EPOCH_ORIGINAL_DEADLINE');
}
function m4ch_http_header(array $frame,string $scope): array
{
    return ['schema'=>1,'scope'=>$scope]+array_intersect_key($frame,array_flip(M4CH_HTTP_IDENTITIES));
}
function m4ch_http_runtime(array $source): array
{
    $runtime=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/runtime.json',16384);
    m4c_entry_keys($runtime,['schema','scope',...M4CH_HTTP_IDENTITIES,'origin']);m4ch_http_identity($runtime,$source);
    m4c_expect($runtime['scope']==='MS4_CUSTOMER_H5_HTTP_RUNTIME'&&$runtime['origin']===$source['h5']['runtime_origin']
        &&$runtime['deadline']-$runtime['not_before']===3600,'M4C_H5_HTTP_ORIGINAL_3600_RUNTIME');return $runtime;
}
function m4ch_http_live_supervisor(array $source,array $runtime): array
{
    $ready=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/source-ready.json',16384);
    m4c_entry_keys($ready,['schema','scope',...M4CH_HTTP_IDENTITIES,'source_binding_sha256','native_created_sha256',
        'public_receipt_sha256','runtime_sha256','supervisor','full_source_verified']);m4ch_http_identity($ready,$source,$runtime);
    foreach(M4CH_HTTP_IDENTITIES as$key)m4c_expect($ready[$key]===$runtime[$key],'M4C_H5_HTTP_READY_SAME_RUNTIME');
    m4c_expect($ready['scope']==='MS4_CUSTOMER_H5_HTTP_SOURCE_READY'&&$ready['full_source_verified']===true
        &&$ready['source_binding_sha256']===$source['source_binding_sha256']&&$ready['native_created_sha256']===$source['native_created_sha256']
        &&$ready['public_receipt_sha256']===$source['public_copy']['sha256']
        &&$ready['runtime_sha256']===m4ch_http_meta(M4CH_HTTP_CONTROL.'/runtime.json')['sha256'],'M4C_H5_HTTP_PRIVATE_READY_BINDINGS');
    m4c_entry_keys($ready['supervisor'],['pid','ppid','pgid','sid','starttime','state']);
    $actual=audit_app_proc_stat($ready['supervisor']['pid']);
    foreach(['pid','pgid','sid','starttime']as$key)m4c_expect($actual!==null&&$actual[$key]===$ready['supervisor'][$key],
        'M4C_H5_HTTP_ACTUAL_LIVE_PARENT');return $ready;
}
function m4ch_http_sealed_inputs(array $source): array
{
    $baseline=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/sql-baseline.json');
    $oracle=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/customer-oracle.json');
    m4c_entry_keys($baseline,[...M4CH_HTTP_PREPARED_IDENTITIES,'scope','native_snapshot']);
    m4c_entry_keys($oracle,[...M4CH_HTTP_PREPARED_IDENTITIES,'scope','fixture','second_store','public_stores','configuration_rows',
        'configuration_values','auxiliary_rows','storefront_native_before','ui_intent','actor_descriptor']);
    foreach(M4CH_HTTP_PREPARED_IDENTITIES as$key)m4c_expect($baseline[$key]===$oracle[$key],'M4C_H5_HTTP_PRIVATE_SAME_NATIVE_PRODUCER');
    $base=m4cb_fixture_load($source);$identity=$source['prepare_identity'];
    $snapshot=m4ch_native_snapshot_restore($baseline['native_snapshot']);
    m4c_expect($baseline['schema']===1&&$baseline['scope']==='NORMAL_CUSTOMER_H5_SQL_BASELINE'
        &&$oracle['scope']==='NORMAL_CUSTOMER_H5_PRIVATE_ORACLE'&&$baseline['run_id']===$source['run_id']
        &&$baseline['source_sha']===$source['source_sha']&&$baseline['freeze_sha256']===$source['freeze_sha256']
        &&$baseline['container_id']===$identity['container_id']&&$baseline['native_created_sha256']===$identity['native_created_sha256']
        &&$baseline['source_binding_sha256']===$identity['source_binding_sha256']
        &&$baseline['public_copy_sha256']===$source['h5']['public_copy_sha256']&&$baseline['public_source_sha256']===$source['h5']['public_source_sha256']
        &&$baseline['runtime_origin']===$source['h5']['runtime_origin']&&$baseline['input_bindings']===$source['h5']['inputs']
        &&m4ch_http_same_environment($baseline['environment'],$source['environment'])
        &&$baseline['self_operated_mer_id']===$source['self_operated_mer_id']&&$baseline['base_fixture_sha256']===$base['sha256']
        &&$baseline['base_prepare_receipt_sha256']===$base['prepare_receipt_sha256']
        &&$baseline['base_prepare_container_id']===$base['private']['prepare_container_id']
        &&$baseline['base_prepare_native_created_sha256']===$base['private']['prepare_native_created_sha256']
        &&$baseline['base_prepare_source_binding_sha256']===$base['private']['prepare_source_binding_sha256']
        &&array_keys($snapshot['facts'])===M4CB_TABLES&&array_keys($snapshot['auxiliary_rows'])===M4CH_AUX_TABLES
        &&$oracle['auxiliary_rows']===$snapshot['auxiliary_rows'],
        'M4C_H5_HTTP_FULL_NATIVE_BASELINE_ORACLE_BINDINGS');
    m4ch_storefront_oracle($oracle,$snapshot['facts']);
    $result=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/prepare-result.json');
    foreach(['sql-baseline','customer-oracle']as$name){$path=M4CH_HTTP_CONTROL.'/'.$name.'.json';$meta=m4ch_http_meta($path);
        $public=$result['business']['sealed_files'][$name];m4c_entry_keys($public,['sha256_prefix','bytes']);
        m4c_expect($public['bytes']===$meta['bytes']&&$public['sha256_prefix']===substr($meta['sha256'],0,12),
            'M4C_H5_HTTP_PREPARE_REAL_SEALED_FILE_SUMMARY');}
    $baseline['facts']=$snapshot['facts'];$baseline['auxiliary_rows']=$snapshot['auxiliary_rows'];
    return ['baseline'=>$baseline,'oracle'=>$oracle,'base'=>$base];
}
function m4ch_http_actor_meta(array $source,array $runtime): array
{
    $sealed=m4ch_http_sealed_inputs($source);$oracle=$sealed['oracle'];$descriptor=$oracle['actor_descriptor'];
    m4c_entry_keys($descriptor,['path','sha256','bytes','owner_uid','mode']);
    m4c_expect($descriptor['path']==='/run/audit-http-browser/customer-actors.json'&&$descriptor['mode']==='0600'
        &&$descriptor['owner_uid']===posix_geteuid()&&is_int($descriptor['bytes'])&&$descriptor['bytes']>0&&$descriptor['bytes']<=16384,
        'M4C_H5_HTTP_ORIGINAL_PRIVATE_ACTOR_DESCRIPTOR');
    $meta=m4ch_http_meta($descriptor['path']);$directory=m4ch_http_directory(dirname($descriptor['path']));
    $actors=m4ch_http_private_read($descriptor['path'],16384);
    m4c_entry_keys($actors,[...M4CH_HTTP_PREPARED_IDENTITIES,'scope','actors']);
    foreach(M4CH_HTTP_PREPARED_IDENTITIES as$key)m4c_expect($actors[$key]===$oracle[$key],'M4C_H5_HTTP_ACTOR_SAME_ORACLE_PRODUCER');
    m4c_entry_keys($actors['actors'],['customer']);$customer=$actors['actors']['customer'];m4c_entry_keys($customer,['uid','account','password']);
    $base=$sealed['base']['private'];m4c_expect($actors['scope']==='NORMAL_CUSTOMER_H5_PRIVATE_ACTORS'
        &&$meta['bytes']===$descriptor['bytes']&&$meta['sha256']===$descriptor['sha256']&&$meta['uid']===$descriptor['owner_uid']
        &&$customer['uid']===(int)$base['fixture']['user']['uid']&&$customer['account']===$base['account']&&$customer['password']===$base['password']
        &&preg_match('/\A[a-f0-9]{10}\z/D',$customer['account'])===1&&preg_match('/\A[a-f0-9]{24}\z/D',$customer['password'])===1
        &&m4ch_http_meta($descriptor['path'])===$meta,'M4C_H5_HTTP_REAL_PRIVATE_NATIVE_CUSTOMER');
    m4c_expect(function_exists('m4ch_browser_intent'),'M4C_H5_HTTP_BROWSER_INTENT_NOT_IMPLEMENTED');$intent=m4ch_browser_intent($oracle);
    m4c_entry_keys($intent,['public_ids','ui_intent']);unset($actors,$customer,$base,$sealed);
    return m4ch_http_header($runtime,'MS4_CUSTOMER_H5_ACTOR_META')+['actor_descriptor'=>$descriptor,'actual_meta'=>$meta,
        'directory_meta'=>$directory,'producer_binding'=>$source['prepare_identity'],'public_ids'=>$intent['public_ids'],'ui_intent'=>$intent['ui_intent']];
}

/** Only the active pointer may be replaced, after the previous original response exists. */
function m4ch_http_active(array $source,array $runtime): array
{
    $pointer=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/active-supervisor.json',16384);
    m4c_entry_keys($pointer,['schema','scope',...M4CH_HTTP_IDENTITIES,'payload_meta']);m4ch_http_identity($pointer,$source,$runtime);
    m4c_expect($pointer['scope']==='MS4_CUSTOMER_H5_HTTP_ACTIVE','M4C_H5_HTTP_ACTIVE_SCOPE');
    $path=M4CH_HTTP_CONTROL.'/supervisor-requests/'.$pointer['step_nonce'].'.json';
    m4c_expect(m4ch_http_meta($path)===$pointer['payload_meta'],'M4C_H5_HTTP_ACTIVE_ACTUAL_REQUEST_BYTES');
    $value=m4ch_http_private_read($path,16384);m4ch_http_identity($value,$source,$runtime);
    foreach(M4CH_HTTP_IDENTITIES as$key)m4c_expect($value[$key]===$pointer[$key],'M4C_H5_HTTP_ACTIVE_SAME_FRAME');
    m4c_entry_keys($value,['schema','scope',...M4CH_HTTP_IDENTITIES,'action']);
    m4c_expect($value['scope']==='MS4_CUSTOMER_H5_HTTP_CONTROL'&&in_array($value['action'],['start','observe','stop'],true),
        'M4C_H5_HTTP_FINITE_SUPERVISOR_ACTION');return $value;
}
function m4ch_http_activate(array $payload,array $source,array $runtime): void
{
    m4c_entry_keys($payload,['schema','scope',...M4CH_HTTP_IDENTITIES,'action']);m4ch_http_identity($payload,$source,$runtime);
    m4c_expect($payload['scope']==='MS4_CUSTOMER_H5_HTTP_CONTROL'&&in_array($payload['action'],['start','observe','stop'],true),
        'M4C_H5_HTTP_FINITE_SUPERVISOR_WRITE');
    $active=M4CH_HTTP_CONTROL.'/active-supervisor.json';$old=null;
    if(file_exists($active)){$old=m4ch_http_meta($active);$previous=m4ch_http_active($source,$runtime);
        m4ch_http_meta(M4CH_HTTP_CONTROL.'/supervisor-responses/'.$previous['step_nonce'].'.json');}
    $meta=m4ch_http_publish(M4CH_HTTP_CONTROL.'/supervisor-requests/'.$payload['step_nonce'].'.json',$payload);
    if($old!==null)m4c_expect(m4ch_http_meta($active)===$old&&unlink($active),'M4C_H5_HTTP_REPLACE_ONLY_OWN_COMPLETED_POINTER');
    m4ch_http_publish($active,m4ch_http_header($payload,'MS4_CUSTOMER_H5_HTTP_ACTIVE')+['payload_meta'=>$meta]);
}
function m4ch_http_status(array $request,array $source,array $runtime): ?array
{
    $path=M4CH_HTTP_CONTROL.'/supervisor-responses/'.$request['step_nonce'].'.json';if(!file_exists($path))return null;
    $reply=m4ch_http_private_read($path);m4ch_http_identity($reply,$source,$runtime);
    m4c_entry_keys($reply,['schema','scope',...M4CH_HTTP_IDENTITIES,'action','status','generation','supervisor','group','members',
        'remaining_members','listener_open','stop_signal','term_attempted','native_child','native_proc_close','normal_only','code']);
    foreach(M4CH_HTTP_IDENTITIES as$key)m4c_expect($reply[$key]===$request[$key],'M4C_H5_HTTP_STATUS_ORIGINAL_REQUEST');
    m4c_expect($reply['scope']==='MS4_CUSTOMER_H5_HTTP_STATUS'&&$reply['action']===$request['action']
        &&in_array($reply['status'],['start','observe','stop','failed'],true)&&$reply['normal_only']===true,
        'M4C_H5_HTTP_ACTUAL_SUPERVISOR_STATUS');return $reply;
}
/** Separate ingress reads numeric process facts; the parent-only group observer is never called here. */
function m4ch_http_child_stop(array $source,array $runtime): array
{
    $ready=m4ch_http_live_supervisor($source,$runtime);$request=m4ch_http_active($source,$runtime);
    m4c_expect($request['action']==='stop','M4C_H5_HTTP_ORIGINAL_STOP_REQUEST');$reply=m4ch_http_status($request,$source,$runtime);
    m4c_expect(is_array($reply)&&$reply['status']==='stop'&&$reply['action']==='stop'&&$reply['members']===[]
        &&$reply['remaining_members']===[]&&$reply['listener_open']===false&&$reply['stop_signal']===SIGTERM
        &&$reply['term_attempted']===true&&is_array($reply['native_child'])&&$reply['native_child']['running']===false
        &&$reply['native_child']['termsig']!==SIGKILL&&is_int($reply['native_proc_close']),
        'M4C_H5_HTTP_REAL_SINGLE_TERM_DIRECT_CHILD_TERMINAL');
    m4c_entry_keys($reply['supervisor'],['pid','ppid','pgid','sid','starttime','state']);
    foreach(['pid','pgid','sid','starttime']as$key)m4c_expect($reply['supervisor'][$key]===$ready['supervisor'][$key],
        'M4C_H5_HTTP_STOP_SAME_LIVE_PARENT');
    m4c_expect(is_string($reply['generation'])&&preg_match('/\A[a-f0-9]{48}\z/D',$reply['generation'])===1,
        'M4C_H5_HTTP_STOP_REAL_GENERATION');
    $startRequest=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/supervisor-requests/'.$reply['generation'].'.json',16384);
    $start=m4ch_http_status($startRequest,$source,$runtime);
    m4c_expect($startRequest['action']==='start'&&is_array($start)&&$start['status']==='start'&&$start['generation']===$reply['generation']
        &&$start['group']===$reply['group']&&$start['supervisor']===$reply['supervisor'],'M4C_H5_HTTP_STOP_ORIGINAL_STARTED_GROUP');
    $group=$reply['group'];m4c_entry_keys($group,['leader_pid','pgid','sid','leader_starttime']);
    m4c_expect($group['leader_pid']>1&&$group['pgid']===$group['leader_pid']&&$group['sid']===$group['leader_pid']
        &&(int)$group['leader_starttime']>=(int)$ready['supervisor']['starttime']
        &&!in_array($group['pgid'],[$ready['supervisor']['pid'],$ready['supervisor']['pgid'],$ready['supervisor']['sid']],true),
        'M4C_H5_HTTP_STOP_REGISTERED_INDEPENDENT_GROUP');
    $stats=glob('/proc/[0-9]*/stat');m4c_expect(is_array($stats)&&$stats!==[],'M4C_H5_HTTP_STOP_NUMERIC_OBSERVATION');
    foreach($stats as$file){$member=audit_app_proc_stat((int)basename(dirname($file)));
        m4c_expect($member===null||$member['pgid']!==$group['pgid'],'M4C_H5_HTTP_ORIGINAL_GROUP_NOW_EMPTY');}
    m4c_expect(!audit_app_listener_open(),'M4C_H5_HTTP_ORIGINAL_LISTENER_NOW_CLOSED');return $reply;
}
function m4ch_http_runtime_create(array $source,array $supervisor): array
{
    foreach(['supervisor-requests','supervisor-responses']as$name){
        $path=M4CH_HTTP_CONTROL.'/'.$name;m4ch_http_directory($path);
        foreach(new DirectoryIterator($path)as$file)m4c_expect($file->isDot(),
            'M4C_H5_HTTP_ORIGINAL_SUPERVISOR_DIRECTORY_EMPTY');}
    foreach(['ui-captures','ui-screenshots','ui-reviews']as$name){
        $path=M4CH_HTTP_CONTROL.'/'.$name;m4c_expect(!file_exists($path)&&!is_link($path)&&mkdir($path,0700),
            'M4C_H5_HTTP_CREATE_ONLY_CONTROL_DIRECTORY');m4ch_http_directory($path);}
    $now=time();$runtime=['schema'=>1,'scope'=>'MS4_CUSTOMER_H5_HTTP_RUNTIME','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
        'container_id'=>$source['container_id'],'freeze_sha256'=>$source['freeze_sha256'],'epoch'=>bin2hex(random_bytes(32)),
        'step_nonce'=>bin2hex(random_bytes(24)),'not_before'=>$now,'deadline'=>$now+3600,'origin'=>$source['h5']['runtime_origin']];
    m4ch_http_identity($runtime,$source);$meta=m4ch_http_publish(M4CH_HTTP_CONTROL.'/runtime.json',$runtime);
    m4ch_http_publish(M4CH_HTTP_CONTROL.'/source-ready.json',m4ch_http_header($runtime,'MS4_CUSTOMER_H5_HTTP_SOURCE_READY')+
        ['source_binding_sha256'=>$source['source_binding_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
         'public_receipt_sha256'=>$source['public_copy']['sha256'],'runtime_sha256'=>$meta['sha256'],
         'supervisor'=>$supervisor,'full_source_verified'=>true]);return $runtime;
}
/** One actual HTTP child. Every exceptional exit drains the same group with at most one TERM attempt. */
function m4ch_http_serve(array $source): int
{
    m4c_expect(!isset($GLOBALS['m4c_application'])&&!audit_app_listener_open(),'M4C_H5_HTTP_PARENT_HAS_NO_APP_OR_LISTENER');
    $supervisor=audit_app_proc_stat(getmypid());m4c_expect(is_array($supervisor),'M4C_H5_HTTP_ACTUAL_PARENT');
    $runtime=m4ch_http_runtime_create($source,$supervisor);$process=null;$group=null;$started=false;$sent=false;$attempted=false;
    $generation='';$last='';$terminate=false;$stopRequest=null;$childProof=null;$failure=null;$failureMeta=null;
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$terminate):void{$terminate=true;});
    try{while(true){
        if($terminate)break;
        if($stopRequest!==null){$stop=m4c_entry_stop($process,$group,$supervisor,$sent,$attempted);
            if(!$stop['stopped']){usleep(20000);continue;}$childProof=$stop;
            $reply=m4ch_http_header($stopRequest,'MS4_CUSTOMER_H5_HTTP_STATUS')+['action'=>'stop','status'=>'stop',
                'generation'=>$generation,'supervisor'=>$supervisor,'members'=>[],'code'=>'M4C_H5_HTTP_CHILD_STOPPED'];
            unset($stop['stopped']);$reply+=$stop;
            m4ch_http_publish(M4CH_HTTP_CONTROL.'/supervisor-responses/'.$stopRequest['step_nonce'].'.json',$reply);
            $stopRequest=null;continue;}
        if(!file_exists(M4CH_HTTP_CONTROL.'/active-supervisor.json')){usleep(50000);continue;}
        // Only a new request is live-time checked. Expiry of a completed request cannot terminate a live child.
        $pointer=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/active-supervisor.json',16384);
        if(($pointer['step_nonce']??null)===$last){usleep(50000);continue;}
        $request=m4ch_http_active($source,$runtime);$last=$request['step_nonce'];m4cb_guard_readonly();
        $reply=m4ch_http_header($request,'MS4_CUSTOMER_H5_HTTP_STATUS')+['action'=>$request['action'],'status'=>'failed',
            'generation'=>$generation,'supervisor'=>$supervisor,'group'=>$group,'members'=>[],'remaining_members'=>[],
            'listener_open'=>audit_app_listener_open(),'stop_signal'=>$sent?SIGTERM:null,'term_attempted'=>$attempted,
            'native_child'=>null,'native_proc_close'=>null,'normal_only'=>true,'code'=>'M4C_H5_HTTP_UNCLASSIFIED'];
        if($request['action']==='start'){
            m4c_expect(!$started&&$process===null&&$group===null&&!audit_app_listener_open(),'M4C_H5_HTTP_ONE_START_NO_RESTART');
            $started=true;$generation=$last;
            $process=proc_open(['setsid','php','think','run','-H','0.0.0.0','-p','8000'],
                [['file','/dev/null','r'],['file','/workspace/crmeb/runtime/ms4-customer-h5-native.log','a'],
                 ['file','/workspace/crmeb/runtime/ms4-customer-h5-native.log','a']],$pipes,'/workspace/crmeb',m4c_entry_child_environment($source));
            m4c_expect(is_resource($process),'M4C_H5_HTTP_ACTUAL_CHILD_OPEN');$group=audit_app_register_group($process,$supervisor);
            $until=microtime(true)+5.0;do{$facts=audit_app_process_observation($group,$supervisor);
                if($facts['members']!==[]&&$facts['listener_open'])break;usleep(10000);
            }while(microtime(true)<$until);
            m4c_expect($facts['members']!==[]&&$facts['listener_open'],'M4C_H5_HTTP_ACTUAL_GROUP_LISTENER');
            $reply=array_replace($reply,$facts,['supervisor'=>$supervisor,'status'=>'start','generation'=>$generation,'code'=>'M4C_H5_HTTP_CHILD_STARTED']);
        }elseif($request['action']==='observe'){
            m4c_expect($group!==null&&$generation!==''&&$childProof===null,'M4C_H5_HTTP_OBSERVE_ORIGINAL_LIVE_GENERATION');
            $facts=audit_app_process_observation($group,$supervisor);
            m4c_expect($facts['members']!==[]&&$facts['listener_open'],'M4C_H5_HTTP_OBSERVE_ACTUAL_LISTENER');
            $reply=array_replace($reply,$facts,['supervisor'=>$supervisor,'status'=>'observe','code'=>'M4C_H5_HTTP_CHILD_OBSERVED']);
        }else{m4c_expect($started&&$childProof===null&&($group!==null||is_resource($process)),
                'M4C_H5_HTTP_STOP_ONLY_ORIGINAL_LIVE_CHILD');$stopRequest=$request;continue;}
        m4ch_http_publish(M4CH_HTTP_CONTROL.'/supervisor-responses/'.$last.'.json',$reply);
    }}catch(Throwable$error){$failure=$error;$failureMeta=[$GLOBALS['m4c_phase']??null,$GLOBALS['m4c_failed_assertion']??null,$GLOBALS['m4c_warning']??null];}
    finally{if(is_resource($process)||$group!==null){do{try{$childProof=m4c_entry_stop($process,$group,$supervisor,$sent,$attempted);}
            catch(Throwable$cleanupError){if($failure===null){$failure=$cleanupError;$failureMeta=[$GLOBALS['m4c_phase']??null,
                $GLOBALS['m4c_failed_assertion']??null,$GLOBALS['m4c_warning']??null];}usleep(20000);}
        }while($childProof===null||!$childProof['stopped']);}}
    if($failure!==null){[$GLOBALS['m4c_phase'],$GLOBALS['m4c_failed_assertion'],$GLOBALS['m4c_warning']]=$failureMeta;throw$failure;}
    m4c_expect($terminate&&!isset($GLOBALS['m4c_application'])&&!audit_app_listener_open(),'M4C_H5_HTTP_NORMAL_PARENT_SIGNAL');
    $full=m4ch_http_source(true);m4c_expect($full['source_binding_sha256']===$source['source_binding_sha256'],'M4C_H5_HTTP_PARENT_FINAL_SOURCE');
    m4ch_http_publish(M4CH_HTTP_CONTROL.'/signal-stop.json',m4ch_http_header($runtime,'MS4_CUSTOMER_H5_HTTP_SIGNAL_STOP')+
        ['normal_only'=>true,'child'=>$childProof,'full_source_unchanged'=>true]);return 0;
}
function m4ch_http_ingress(string $mode,array $source): void
{
    $runtime=m4ch_http_runtime($source);m4ch_http_live_supervisor($source,$runtime);
    if($mode==='runtime')$value=$runtime;
    elseif($mode==='actor-meta')$value=m4ch_http_actor_meta($source,$runtime);
    elseif($mode==='supervisor'){$request=m4ch_http_active($source,$runtime);$reply=m4ch_http_status($request,$source,$runtime);
        $value=m4ch_http_header($request,'MS4_CUSTOMER_H5_HTTP_SUPERVISOR_REPLY')+['state'=>$reply===null?'not_ready':'response','response'=>$reply];}
    elseif($mode==='finish'){$value=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/finish-browser.json',16384);
        m4ch_http_identity($value,$source,$runtime);m4c_entry_keys($value,['schema','scope',...M4CH_HTTP_IDENTITIES,'action']);
        m4c_expect($value['scope']==='MS4_CUSTOMER_H5_BROWSER_FINISH'&&$value['action']==='close-owned-tabs','M4C_H5_HTTP_ACTUAL_FINISH');}
    elseif($mode==='stage'||$mode==='write'){
        $raw=stream_get_contents(STDIN,2097153);m4c_expect(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=2097152,'M4C_H5_HTTP_PRIVATE_STDIN_LIMIT');
        $frame=json_decode($raw,true,128,JSON_THROW_ON_ERROR);m4c_expect(is_array($frame)&&!array_is_list($frame),'M4C_H5_HTTP_PRIVATE_STDIN_OBJECT');
        if($mode==='stage'){m4c_expect(function_exists('m4ch_stage'),'M4C_H5_HTTP_STAGE_NOT_IMPLEMENTED');$value=m4ch_stage($source,$runtime,$frame);}
        else{m4c_expect(function_exists('m4ch_http_write'),'M4C_H5_HTTP_UI_WRITE_NOT_IMPLEMENTED');m4ch_http_write($frame,$source,$runtime);
            m4ch_http_source(false);fwrite(STDOUT,"MS4_PRIVATE_WRITE_OK\n");return;}
    }else{m4c_expect($mode==='audit'&&function_exists('m4ch_http_audit'),'M4C_H5_HTTP_FINAL_AUDIT_NOT_IMPLEMENTED');
        $value=m4ch_http_audit($source,$runtime);}
    m4ch_http_source(false);fwrite(STDOUT,m4cb_json($value));
}
const M4CH_HTTP_SCENES=['login-empty','store-a','store-b','product-a','product-b','product-c','cart-three-lines','confirm-filled','order-detail-unpaid'];
function m4ch_http_same(mixed $left,mixed $right): bool
{
    $canonical=static function(mixed$value)use(&$canonical):mixed{if(!is_array($value))return$value;
        if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as$key=>$item)$value[$key]=$canonical($item);return$value;};
    return $canonical($left)===$canonical($right);
}
function m4ch_http_viewport(array $value): void
{
    m4c_entry_keys($value,['width','height']);m4c_expect(in_array($value['width'],[320,390,1440],true)
        &&is_int($value['height'])&&$value['height']>=600&&$value['height']<=1200,'M4C_H5_HTTP_TARGET_VIEWPORT');
}
function m4ch_http_box(array $value): void
{
    m4c_entry_keys($value,['x','y','width','height']);foreach($value as$number)m4c_expect((is_int($number)||is_float($number))
        &&is_finite((float)$number),'M4C_H5_HTTP_ACTUAL_FINITE_DOM_BOX');
    m4c_expect($value['width']>=0&&$value['height']>=0,'M4C_H5_HTTP_NONNEGATIVE_DOM_BOX');
}
/** Pure normal redirect URL gate. The original fresh key is bound later by B3 and final coverage. */
function m4ch_http_confirmation_url(string $url,array $source): string
{
    $parts=parse_url($url);m4c_expect(strlen($url)<=8192&&is_array($parts)&&!isset($parts['user'])&&!isset($parts['pass'])
        &&!isset($parts['fragment'])&&($parts['scheme']??null)==='http'&&($parts['host']??null)==='127.0.0.1'
        &&'http://127.0.0.1:'.($parts['port']??0)===$source['h5']['runtime_origin']
        &&($parts['path']??null)==='/pages/goods/order_confirm/index'&&is_string($parts['query']??null),
        'M4C_H5_HTTP_NORMAL_REDIRECT_ACTUAL_ORIGIN_AND_PATH');
    $pairs=explode('&',$parts['query']);m4c_expect(count($pairs)===1,'M4C_H5_HTTP_REDIRECT_ONLY_ONE_ORDER_KEY_QUERY');
    $pair=explode('=',$pairs[0],2);m4c_expect(count($pair)===2&&$pair[0]==='orderKey','M4C_H5_HTTP_REDIRECT_ONLY_ORDER_KEY');
    $key=rawurldecode($pair[1]);$encoded=str_replace(['%21','%27','%28','%29','%2A'],['!',"'",'(',')','*'],rawurlencode($key));
    m4c_expect(preg_match('/\A[\x21-\x7e]{1,32}\z/D',$key)===1&&$encoded===$pair[1],
        'M4C_H5_HTTP_CANONICAL_REAL_REDIRECT_CONFIRMATION_KEY');return$key;
}
function m4ch_http_ui_network(array $network): void
{
    m4c_expect(array_is_list($network)&&count($network)<=4096,'M4C_H5_HTTP_BOUNDED_UI_NETWORK');$seen=[];
    $gets=['/api/basic_config','/api/theme_info/theme','/api/theme_info/detail','/api/lang_version','/api/get_lang_json','/api/copyright',
        '/api/wechat/get_logo','/api/theme/navigation','/api/v2/diy/get_version/0','/api/user','/api/cart/count','/api/cart/list',
        '/api/product/hot','/api/products','/api/marketplace/stores/:merchant','/api/product/detail/:product',
        '/api/product/real_price/:product/:sku','/api/address/default','/api/address/list','/api/address/detail/:address',
        '/api/v2/invoice','/api/order/detail/:order','/api/get_customer_type','/api/get_script'];
    $posts=['/api/login','/api/image_base64','/api/cart/add','/api/cart/num','/api/order/check_shipping','/api/order/confirm',
        '/api/order/confirmation/:confirmation','/api/order/confirmation/:confirmation/restore',
        '/api/order/computed/:confirmation','/api/order/create/:confirmation'];
    foreach($network as$row){m4c_expect(is_array($row),'M4C_H5_HTTP_UI_NETWORK_ROW');m4c_entry_keys($row,['request_id','projection']);
        $id=$row['request_id'];$p=$row['projection'];m4c_expect(is_string($id)&&$id!==''&&strlen($id)<=256&&!isset($seen[$id])&&is_array($p),
            'M4C_H5_HTTP_UI_REAL_UNIQUE_REQUEST');$seen[$id]=true;
        m4c_entry_keys($p,['scope','method','path_template','http_status','response_kind','business_status','nested_status','response_bytes',
            'response_sha256_prefix','private_capture_sha256_prefix']);
        m4c_expect($p['scope']==='NETWORK_ONLY_NOT_DOM_ACTION_ACK_JPEG_OR_UI_ACCEPTANCE'&&in_array($p['method'],['GET','POST'],true)
            &&in_array($p['path_template'],$p['method']==='GET'?$gets:$posts,true)&&$p['http_status']===200
            &&is_int($p['response_bytes'])&&$p['response_bytes']>=0&&$p['response_bytes']<=2097152,
            'M4C_H5_HTTP_UI_SAFE_FINITE_NETWORK_TEMPLATE');
        foreach(['response_sha256_prefix','private_capture_sha256_prefix']as$key)m4c_expect(is_string($p[$key])
            &&preg_match('/\A[a-f0-9]{12}\z/D',$p[$key])===1,'M4C_H5_HTTP_UI_PRIVATE_HASH_PREFIX_ONLY');
        if($p['path_template']==='/api/get_script')m4c_expect($p['response_kind']==='raw_text'&&$p['business_status']===null
            &&$p['nested_status']===null,'M4C_H5_HTTP_UI_RAW_STATISTICS_TEXT');
        else{m4c_expect($p['response_kind']==='json'&&$p['business_status']===200,'M4C_H5_HTTP_UI_REAL_BUSINESS_SUCCESS');
            m4c_expect(match($p['path_template']){'/api/order/computed/:confirmation'=>in_array($p['nested_status'],['NONE','SUCCESS'],true),
                '/api/order/create/:confirmation'=>$p['nested_status']==='SUCCESS',default=>$p['nested_status']===null},
                'M4C_H5_HTTP_UI_ACTUAL_NESTED_STATUS');}}
}
/** These checks constrain records from Root's actual DOM read; they never generate DOM or assert visual review. */
/** Consume only the real DOM fields produced by the existing browser writer. */
function m4ch_http_storefront_dom(array $dom,string $scene,array $intent): void
{
    $index=array_search($scene,['product-a','product-b','product-c'],true);
    if($index!==false){$product=$intent['products'][$index];$store=array_values(array_filter($intent['stores'],
        static fn(array$row):bool=>$row['mer_id']===$product['mer_id']));
        m4c_expect(count($store)===1&&str_contains($dom['body_text'],$store[0]['name'])
            &&str_contains($dom['body_text'],'商品所属店铺'),'M4C_H5_HTTP_DETAIL_SHOWS_ACTUAL_NATIVE_STORE_NAME');
        $entries=array_values(array_filter($dom['controls'],static fn(array$row):bool=>$row['name']==='进入店铺 '.$store[0]['name']
            &&str_contains($row['text'],'进入店铺')&&!$row['disabled']));
        m4c_expect(count($entries)===1,'M4C_H5_HTTP_DETAIL_REAL_UNIQUE_STORE_ENTRY');
    }elseif(in_array($scene,['store-a','store-b'],true)){$store=$intent['stores'][$scene==='store-a'?0:1];$expected=[];$actual=[];
        foreach($intent['storefront_products']as$product)if($product['mer_id']===$store['mer_id'])$expected[]='查看商品 '.$product['store_name'];
        foreach($dom['controls']as$control)if($control['role']==='link'){$actual[]=$control['name'];
            m4c_expect(!$control['disabled'],'M4C_H5_HTTP_STORE_REAL_ENABLED_PRODUCT_ROWS');}
        sort($expected,SORT_STRING);sort($actual,SORT_STRING);
        m4c_expect($expected!==[]&&$actual===$expected&&str_contains($dom['body_text'],'本店商品')
            &&str_contains($dom['body_text'],'本店商品已加载完'),'M4C_H5_HTTP_STORE_COMPLETE_NATIVE_PUBLISHED_PRODUCT_ROW_MULTISET');
    }
}
/** Genuine click receipts retain the original three fields and actual screenshot creation order. */
function m4ch_http_storefront_click_chain(array $rows,array $intent): void
{
    usort($rows,static fn(array$a,array$b):int=>$a['sequence']<=>$b['sequence']);$entered=[];$returned=[];
    foreach($rows as$row){$capture=$row['capture'];$scene=$capture['scene'];
        foreach($capture['actions']as$action){if($action['action']!=='click')continue;$target=$action['target'];
            if(in_array($target,['store-entry-a','store-entry-b'],true)){$label=substr($target,-1);$store=$intent['stores'][$label==='a'?0:1];
                m4c_expect($scene==='store-'.$label&&str_contains($capture['dom']['body_text'],$store['name']),
                    'M4C_H5_HTTP_REAL_STORE_ENTRY_COMPLETES_AT_MATCHING_SHOP');$entered[$label]=$row['sequence'];
            }elseif(in_array($target,['store-product-a','store-product-b','store-product-c'],true)){$i=array_search($target,
                    ['store-product-a','store-product-b','store-product-c'],true);$product=$intent['products'][$i];
                $label=$product['mer_id']===$intent['stores'][0]['mer_id']?'a':'b';
                m4c_expect($scene===['product-a','product-b','product-c'][$i]&&isset($entered[$label])&&$entered[$label]<$row['sequence'],
                    'M4C_H5_HTTP_REAL_SHOP_ROW_RETURNS_TO_ITS_PRODUCT_AFTER_ENTRY');$returned[$label]=true;}
        }
    }
    m4c_expect(isset($entered['a'],$entered['b'],$returned['a'],$returned['b']),
        'M4C_H5_HTTP_BOTH_REAL_PRODUCT_SHOP_PRODUCT_CLICK_CHAINS');
}

function m4ch_http_ui_capture(array $payload,array $source,array $runtime): void
{
    m4c_entry_keys($payload,['schema','scope',...M4CH_HTTP_IDENTITIES,'stage','scene','viewport','actions','dom','network']);
    m4ch_http_identity($payload,$source,$runtime);m4c_expect($payload['scope']==='MS4_CUSTOMER_H5_UI_CAPTURE'
        &&in_array($payload['scene'],M4CH_HTTP_SCENES,true),'M4C_H5_HTTP_UI_ACTUAL_SCENE');
    $expected=match($payload['scene']){'login-empty'=>'B1','store-a','store-b','product-a','product-b','product-c','cart-three-lines'=>'B2',
        'confirm-filled'=>'B3','order-detail-unpaid'=>'B4'};
    m4c_expect($payload['stage']===$expected,'M4C_H5_HTTP_UI_REAL_PHASE');m4ch_http_viewport($payload['viewport']);
    m4c_expect(is_array($payload['actions'])&&array_is_list($payload['actions'])&&count($payload['actions'])<=256,
        'M4C_H5_HTTP_UI_BOUNDED_ACTUAL_ACTIONS');
    foreach($payload['actions']as$action){m4c_expect(is_array($action),'M4C_H5_HTTP_UI_ACTION_OBJECT');m4c_entry_keys($action,['action','target','completed']);
        m4c_expect(in_array($action['action'],['navigate','click','fill','check','back','resize'],true)&&$action['completed']===true
            &&is_string($action['target'])&&$action['target']!==''&&strlen($action['target'])<=512&&!str_contains($action['target'],"\0"),
            'M4C_H5_HTTP_UI_NORMAL_ACTION_WITHOUT_VALUES');}
    $dom=$payload['dom'];m4c_expect(is_array($dom),'M4C_H5_HTTP_UI_REAL_DOM_OBJECT');
    m4c_entry_keys($dom,['url','width','height','scroll_width','body_text','controls','images','password_values_absent','credential_query_absent']);
    m4c_expect($dom['width']===$payload['viewport']['width']&&$dom['height']===$payload['viewport']['height']&&is_int($dom['scroll_width'])
        &&$dom['scroll_width']<=$dom['width']&&$dom['scroll_width']>0&&is_string($dom['body_text'])&&strlen($dom['body_text'])<=262144
        &&$dom['password_values_absent']===true&&$dom['credential_query_absent']===true,'M4C_H5_HTTP_UI_REAL_DOM_BOUNDS_AND_NO_PASSWORD_VALUES');
    $sealed=m4ch_http_sealed_inputs($source);$intent=m4ch_browser_intent($sealed['oracle'])['ui_intent'];$parts=parse_url($dom['url']);
    m4c_expect(is_string($dom['url'])&&strlen($dom['url'])<=8192&&is_array($parts)&&!isset($parts['user'])&&!isset($parts['pass'])
        &&!isset($parts['fragment'])&&($parts['scheme']??null)==='http'&&($parts['host']??null)==='127.0.0.1'
        &&'http://127.0.0.1:'.($parts['port']??0)===$source['h5']['runtime_origin'],'M4C_H5_HTTP_UI_ACTUAL_ORIGIN');
    $path=$parts['path']??'';$query=[];if(isset($parts['query']))foreach(explode('&',$parts['query'])as$pair){$kv=explode('=',$pair,2);
        $key=rawurldecode($kv[0]);m4c_expect(!isset($query[$key]),'M4C_H5_HTTP_UI_UNIQUE_QUERY');$query[$key]=rawurldecode($kv[1]??'');}
    $scene=$payload['scene'];$productIndex=array_search($scene,['product-a','product-b','product-c'],true);
    if($scene==='login-empty')m4c_expect($path==='/pages/users/login/index'&&$query===[],'M4C_H5_HTTP_UI_REAL_LOGIN_PAGE');
    elseif(in_array($scene,['store-a','store-b'],true)){$index=$scene==='store-a'?0:1;
        m4c_expect($path==='/pages/store/index'&&m4ch_http_same($query,['mer_id'=>(string)$intent['stores'][$index]['mer_id']])
            &&str_contains($dom['body_text'],$intent['stores'][$index]['name']),'M4C_H5_HTTP_UI_REAL_STORE_ID_AND_NAME');}
    elseif($productIndex!==false){$product=$intent['products'][$productIndex];m4c_expect($path==='/pages/goods_details/index'
            &&m4ch_http_same($query,['id'=>(string)$product['product_id']])&&str_contains($dom['body_text'],$product['store_name'])
            &&str_contains($dom['body_text'],'商品所属店铺'),'M4C_H5_HTTP_UI_REAL_PRODUCT_DETAIL');}
    elseif($scene==='cart-three-lines')m4c_expect($path==='/pages/order_addcart/order_addcart'&&$query===[]
        &&str_contains($dom['body_text'],$intent['stores'][0]['name'])&&str_contains($dom['body_text'],$intent['stores'][1]['name']),
        'M4C_H5_HTTP_UI_REAL_TWO_STORE_CART');
    elseif($scene==='confirm-filled'){
        m4ch_http_confirmation_url($dom['url'],$source);
        m4c_expect(str_contains($dom['body_text'],'提交订单'),'M4C_H5_HTTP_UI_REAL_CONFIRMATION_PAGE');
    }
    else m4c_expect($path==='/pages/goods/order_details/index'&&array_keys($query)===['order_id']&&$query['order_id']!==''
        &&str_contains($dom['body_text'],$intent['stores'][0]['name']),'M4C_H5_HTTP_UI_REAL_UNPAID_DETAIL_PAGE');
    m4c_expect(is_array($dom['controls'])&&array_is_list($dom['controls'])&&count($dom['controls'])<=1024
        &&is_array($dom['images'])&&array_is_list($dom['images'])&&count($dom['images'])<=1024,'M4C_H5_HTTP_UI_BOUNDED_REAL_DOM_NODES');
    foreach($dom['controls']as$control){m4c_expect(is_array($control),'M4C_H5_HTTP_UI_CONTROL_OBJECT');
        m4c_entry_keys($control,['role','name','text','disabled','checked','box']);
        foreach(['role','name','text']as$key)m4c_expect(is_string($control[$key])&&strlen($control[$key])<=4096,'M4C_H5_HTTP_UI_CONTROL_TEXT');
        m4c_expect(is_bool($control['disabled'])&&($control['checked']===null||is_bool($control['checked'])),'M4C_H5_HTTP_UI_CONTROL_STATE');
        m4ch_http_box($control['box']);}
    foreach($dom['images']as$image){m4c_expect(is_array($image),'M4C_H5_HTTP_UI_IMAGE_OBJECT');
        m4c_entry_keys($image,['src','current_src','complete','natural_width','natural_height','box']);
        m4c_expect(is_string($image['src'])&&strlen($image['src'])<=8192&&is_string($image['current_src'])&&strlen($image['current_src'])<=8192
            &&is_bool($image['complete'])&&is_int($image['natural_width'])&&is_int($image['natural_height'])
            &&$image['natural_width']>=0&&$image['natural_height']>=0,'M4C_H5_HTTP_UI_ACTUAL_IMAGE_FACTS');m4ch_http_box($image['box']);}
    m4ch_http_storefront_dom($dom,$scene,$intent);
    m4ch_http_ui_network($payload['network']);$raw=m4cb_json($payload);
    foreach(['password']as$key)m4c_expect(!str_contains($raw,$sealed['base']['private'][$key]),'M4C_H5_HTTP_UI_NO_PRIVATE_PASSWORD_IN_RECORD');
    unset($sealed,$intent);
}
function m4ch_http_screenshot(array $payload,array $source,array $runtime): void
{
    m4c_entry_keys($payload,['schema',...M4CH_HTTP_IDENTITIES,'scene','path','bytes','sha256','viewport','pixels','dom']);
    m4ch_http_identity($payload,$source,$runtime);$capture=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/ui-captures/'.$payload['step_nonce'].'.json');
    m4ch_http_ui_capture($capture,$source,$runtime);foreach(M4CH_HTTP_IDENTITIES as$key)m4c_expect($payload[$key]===$capture[$key],
        'M4C_H5_HTTP_SCREENSHOT_ORIGINAL_CAPTURE_FRAME');
    m4c_expect($payload['scene']===$capture['scene']&&m4ch_http_same($payload['viewport'],$capture['viewport'])
        &&m4ch_http_same($payload['dom'],$capture['dom']),'M4C_H5_HTTP_SCREENSHOT_ORIGINAL_CAPTURE_DOM');m4ch_http_viewport($payload['viewport']);
    m4c_entry_keys($payload['pixels'],['width','height']);$scene=$payload['scene'];
    m4c_expect(is_string($payload['path'])&&preg_match('~\Aevidence/browser-screenshots/([0-9]{4})-'.preg_quote($scene,'~').'\.jpg\z~D',$payload['path'],$match)===1
        &&(int)$match[1]>0&&is_int($payload['bytes'])&&$payload['bytes']>0&&$payload['bytes']<=16777216
        &&is_string($payload['sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$payload['sha256'])===1,
        'M4C_H5_HTTP_PUBLIC_SCREENSHOT_SEQUENCE_NO_PRIVATE_NONCE');
    $files=glob(M4CH_HTTP_CONTROL.'/ui-screenshots/*.json');m4c_expect(is_array($files),'M4C_H5_HTTP_SCREENSHOT_METADATA_DIRECTORY');
    $existing=M4CH_HTTP_CONTROL.'/ui-screenshots/'.$payload['step_nonce'].'.json';$seen=[];
    foreach($files as$file){$old=m4ch_http_private_read($file,2097152);m4c_expect(is_string($old['path']??null)&&!isset($seen[$old['path']]),
            'M4C_H5_HTTP_SCREENSHOT_UNIQUE_ACTUAL_PATH');$seen[$old['path']]=true;}
    if(file_exists($existing))m4c_expect(m4ch_http_same(m4ch_http_private_read($existing),$payload),'M4C_H5_HTTP_EXISTING_SCREENSHOT_SAME_METADATA');
    else m4c_expect((int)$match[1]===count($files)+1&&!isset($seen[$payload['path']]),'M4C_H5_HTTP_SCREENSHOT_ACTUAL_SEQUENTIAL_CREATE');
    $path='/run/audit/'.$payload['path'];clearstatcache(true,$path);$stat=lstat($path);$image=getimagesize($path);
    m4c_expect(is_array($stat)&&is_file($path)&&!is_link($path)&&realpath($path)===$path&&$stat['size']===$payload['bytes']
        &&hash_file('sha256',$path)===$payload['sha256']&&is_array($image)&&($image['mime']??null)==='image/jpeg'
        &&$image[0]===$payload['pixels']['width']&&$image[1]===$payload['pixels']['height']
        &&is_int($payload['pixels']['width'])&&$payload['pixels']['width']>0
        &&is_int($payload['pixels']['height'])&&$payload['pixels']['height']>0,'M4C_H5_HTTP_ORIGINAL_JPEG_BYTES_MIME_AND_PIXELS');
}
function m4ch_http_review(array $payload,array $source,array $runtime): void
{
    m4c_entry_keys($payload,['schema','scope',...M4CH_HTTP_IDENTITIES,'scene','sha256','reviewed','issues','review_method']);
    m4ch_http_identity($payload,$source,$runtime);$shot=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/ui-screenshots/'.$payload['step_nonce'].'.json');
    m4ch_http_screenshot($shot,$source,$runtime);foreach(M4CH_HTTP_IDENTITIES as$key)m4c_expect($payload[$key]===$shot[$key],
        'M4C_H5_HTTP_REVIEW_SAME_ORIGINAL_CAPTURE_FRAME');
    m4c_expect($payload['scope']==='MS4_CUSTOMER_H5_UI_VISUAL_REVIEW'&&$payload['scene']===$shot['scene']&&$payload['sha256']===$shot['sha256']
        &&$payload['reviewed']===true&&$payload['issues']===[]&&$payload['review_method']==='actual_image_inspection',
        'M4C_H5_HTTP_ROOT_ORIGINAL_IMAGE_REVIEW_RECORD');
}
function m4ch_http_ui_coverage(array $source,array $runtime): array
{
    $directories=[];foreach(['ui-captures','ui-screenshots','ui-reviews']as$directory){m4ch_http_directory(M4CH_HTTP_CONTROL.'/'.$directory);
        $names=[];foreach(new DirectoryIterator(M4CH_HTTP_CONTROL.'/'.$directory)as$file){if($file->isDot())continue;
            m4c_expect($file->isFile()&&!$file->isLink()&&preg_match('/\A[a-f0-9]{48}\.json\z/D',$file->getFilename())===1,
                'M4C_H5_HTTP_CLOSED_UI_METADATA_FILENAMES');$names[]=$file->getFilename();}sort($names,SORT_STRING);$directories[$directory]=$names;}
    m4c_expect($directories['ui-captures']===$directories['ui-screenshots']&&$directories['ui-captures']===$directories['ui-reviews'],
        'M4C_H5_HTTP_EVERY_ACTUAL_CAPTURE_HAS_ORIGINAL_JPEG_AND_REVIEW');$coverage=[];$network=[];$stages=[];$storefrontRows=[];
    foreach(['B0','B1','B2','B3','B4']as$stage){$record=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/stage-'.$stage.'.json');
        m4ch_http_identity($record,$source,$runtime);m4c_expect(($record['stage']??null)===$stage,'M4C_H5_HTTP_COMPLETE_REAL_SQL_STAGES');
        $stages[]=$record;
        foreach($record['network']as$row){m4c_expect(!isset($network[$row['request_id']]),'M4C_H5_HTTP_UNIQUE_REAL_STAGE_REQUESTS');$network[$row['request_id']]=$row['projection'];}}
    $b3=$stages[3];$key=$b3['business']['confirmation_key']??null;$frame=$b3['business']['confirmation_frame']??null;
    m4c_expect(is_string($key)&&preg_match('/\A[\x21-\x7e]{1,32}\z/D',$key)===1&&is_array($frame)
        &&($b3['business']['frame_read_rows_unchanged']??null)===true,'M4C_H5_HTTP_ORIGINAL_B3_CONFIRMATION_PROOF');
    $sealed=m4ch_http_sealed_inputs($source);m4ch_stage_restore_chain($stages,$sealed['oracle'],$frame,$key);
    $storefrontIntent=m4ch_browser_intent($sealed['oracle'])['ui_intent'];unset($sealed);
    foreach($directories['ui-captures']as$name){$capture=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/ui-captures/'.$name);
        m4ch_http_ui_capture($capture,$source,$runtime);
        if($capture['scene']==='confirm-filled')m4c_expect(m4ch_http_confirmation_url($capture['dom']['url'],$source)===$key,
            'M4C_H5_HTTP_FINAL_CAPTURE_ORIGINAL_B3_KEY_AND_RESTORE');
        $shot=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/ui-screenshots/'.$name);
        m4ch_http_screenshot($shot,$source,$runtime);$review=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/ui-reviews/'.$name);
        m4ch_http_review($review,$source,$runtime);$coverage[$capture['scene']][$capture['viewport']['width']]=true;
        m4c_expect(preg_match('~\Aevidence/browser-screenshots/([0-9]{4})-~D',$shot['path'],$storefrontSequence)===1,
            'M4C_H5_HTTP_STOREFRONT_ORIGINAL_SCREENSHOT_SEQUENCE');
        $storefrontRows[]=['sequence'=>(int)$storefrontSequence[1],'capture'=>$capture];
        foreach($capture['network']as$row)m4c_expect(isset($network[$row['request_id']])&&m4ch_http_same($network[$row['request_id']],$row['projection']),
            'M4C_H5_HTTP_UI_NETWORK_BOUND_TO_ACTUAL_SQL_STAGE_CAPTURE');}
    m4ch_http_storefront_click_chain($storefrontRows,$storefrontIntent);
    foreach(M4CH_HTTP_SCENES as$scene)foreach([320,390,1440]as$width)m4c_expect(isset($coverage[$scene][$width]),
        'M4C_H5_HTTP_ALL_REAL_SCENES_AT_THREE_WIDTHS_REVIEWED');return$directories;
}
function m4ch_http_browser_closed(array $source,array $runtime): array
{
    $finish=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/finish-browser.json',16384);m4ch_http_identity($finish,$source,$runtime);
    m4c_entry_keys($finish,['schema','scope',...M4CH_HTTP_IDENTITIES,'action']);
    m4c_expect($finish['scope']==='MS4_CUSTOMER_H5_BROWSER_FINISH'&&$finish['action']==='close-owned-tabs','M4C_H5_HTTP_FINITE_FINISH_BEFORE_CLOSED');
    $closed=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/browser-closed.json',16384);m4ch_http_identity($closed,$source,$runtime);
    m4c_entry_keys($closed,['schema','scope',...M4CH_HTTP_IDENTITIES,'closed_owned_tab_ids','viewport_reset']);
    foreach(M4CH_HTTP_IDENTITIES as$key)m4c_expect($closed[$key]===$finish[$key],'M4C_H5_HTTP_CLOSED_SAME_FINISH_FRAME');
    m4c_expect($closed['scope']==='MS4_CUSTOMER_H5_BROWSER_CLOSED'&&$closed['viewport_reset']===true&&is_array($closed['closed_owned_tab_ids'])
        &&array_is_list($closed['closed_owned_tab_ids'])&&count($closed['closed_owned_tab_ids'])===1
        &&is_string($closed['closed_owned_tab_ids'][0])&&$closed['closed_owned_tab_ids'][0]!==''
        &&strlen($closed['closed_owned_tab_ids'][0])<=256,'M4C_H5_HTTP_ONE_REAL_OWNED_TAB_CLOSED');return$finish;
}
function m4ch_http_write(array $frame,array $source,array $runtime): void
{
    m4c_entry_keys($frame,['kind','payload']);m4c_expect(is_string($frame['kind'])&&is_array($frame['payload']),
        'M4C_H5_HTTP_FIXED_PRIVATE_WRITE_FRAME');$kind=$frame['kind'];$payload=$frame['payload'];m4ch_http_identity($payload,$source,$runtime);
    $nonce=$payload['step_nonce'];
    if($kind==='supervisor'){m4ch_http_activate($payload,$source,$runtime);return;}
    if($kind==='ui-capture'){m4c_expect(function_exists('m4ch_browser_intent'),'M4C_H5_HTTP_BROWSER_INTENT_NOT_IMPLEMENTED');
        m4ch_http_ui_capture($payload,$source,$runtime);m4ch_http_publish(M4CH_HTTP_CONTROL.'/ui-captures/'.$nonce.'.json',$payload);return;}
    if($kind==='ui-screenshot'){m4ch_http_screenshot($payload,$source,$runtime);
        m4ch_http_publish(M4CH_HTTP_CONTROL.'/ui-screenshots/'.$nonce.'.json',$payload);return;}
    if($kind==='ui-review'){m4ch_http_review($payload,$source,$runtime);m4ch_http_publish(M4CH_HTTP_CONTROL.'/ui-reviews/'.$nonce.'.json',$payload);return;}
    if($kind==='finish'){m4c_entry_keys($payload,['schema','scope',...M4CH_HTTP_IDENTITIES,'action']);
        m4c_expect($payload['scope']==='MS4_CUSTOMER_H5_BROWSER_FINISH'&&$payload['action']==='close-owned-tabs','M4C_H5_HTTP_FINITE_BROWSER_FINISH');
        m4ch_http_child_stop($source,$runtime);m4ch_http_ui_coverage($source,$runtime);
        m4ch_http_publish(M4CH_HTTP_CONTROL.'/finish-browser.json',$payload);return;}
    m4c_expect($kind==='closed','M4C_H5_HTTP_FINITE_PRIVATE_WRITE_KIND');
    m4c_entry_keys($payload,['schema','scope',...M4CH_HTTP_IDENTITIES,'closed_owned_tab_ids','viewport_reset']);
    $finish=m4ch_http_private_read(M4CH_HTTP_CONTROL.'/finish-browser.json',16384);
    foreach(M4CH_HTTP_IDENTITIES as$key)m4c_expect($payload[$key]===$finish[$key],'M4C_H5_HTTP_CLOSED_ORIGINAL_FINISH');
    m4c_expect($payload['scope']==='MS4_CUSTOMER_H5_BROWSER_CLOSED'&&$payload['viewport_reset']===true
        &&is_array($payload['closed_owned_tab_ids'])&&array_is_list($payload['closed_owned_tab_ids'])&&count($payload['closed_owned_tab_ids'])===1
        &&is_string($payload['closed_owned_tab_ids'][0])&&$payload['closed_owned_tab_ids'][0]!==''&&strlen($payload['closed_owned_tab_ids'][0])<=256,
        'M4C_H5_HTTP_CLOSED_ONLY_ONE_OWNED_TAB');m4ch_http_publish(M4CH_HTTP_CONTROL.'/browser-closed.json',$payload);
}
function m4ch_http_audit(array $source,array $runtime): array
{
    m4c_expect(function_exists('m4ch_stage_final'),'M4C_H5_HTTP_FINAL_STAGE_NOT_IMPLEMENTED');m4ch_http_child_stop($source,$runtime);
    $before=['baseline'=>m4ch_http_meta(M4CH_HTTP_CONTROL.'/sql-baseline.json'),'oracle'=>m4ch_http_meta(M4CH_HTTP_CONTROL.'/customer-oracle.json'),
        'actors'=>m4ch_http_meta('/run/audit-http-browser/customer-actors.json')];
    m4ch_http_ui_coverage($source,$runtime);$finish=m4ch_http_browser_closed($source,$runtime);$full=m4ch_http_source(true);
    m4c_expect($full['source_binding_sha256']===$source['source_binding_sha256'],'M4C_H5_HTTP_FINAL_FULL_SOURCE_BEFORE_NATIVE');
    $proof=m4ch_stage_final($source,$runtime);$full=m4ch_http_source(true);
    m4c_expect($full['source_binding_sha256']===$source['source_binding_sha256']&&$before===['baseline'=>m4ch_http_meta(M4CH_HTTP_CONTROL.'/sql-baseline.json'),
        'oracle'=>m4ch_http_meta(M4CH_HTTP_CONTROL.'/customer-oracle.json'),'actors'=>m4ch_http_meta('/run/audit-http-browser/customer-actors.json')],
        'M4C_H5_HTTP_FINAL_FULL_SOURCE_AND_SEALED_METADATA_UNCHANGED');
    m4c_entry_keys($proof,['schema','scope',...M4CH_HTTP_IDENTITIES,'verified','business_table_count','auxiliary_table_count',
        'private_snapshot_sha256_prefix','full_source_unchanged','scope_limit']);m4ch_http_identity($proof,$source,$runtime);
    foreach(M4CH_HTTP_IDENTITIES as$key)m4c_expect($proof[$key]===$finish[$key],'M4C_H5_HTTP_SQL_AUDIT_SAME_FINISH');
    m4c_expect($proof['scope']==='MS4_CUSTOMER_H5_SQL_AUDIT'&&$proof['verified']===true&&$proof['business_table_count']===27
        &&$proof['auxiliary_table_count']===7&&$proof['full_source_unchanged']===true
        &&$proof['scope_limit']==='OBSERVED_27_BUSINESS_PLUS_7_AUXILIARY_TABLES_ONLY'
        &&preg_match('/\A[a-f0-9]{12}\z/D',$proof['private_snapshot_sha256_prefix'])===1,'M4C_H5_HTTP_FINITE_FINAL_SQL_AUDIT_PROOF');
    m4ch_http_publish(M4CH_HTTP_CONTROL.'/sql-audit.json',$proof);return$proof;
}
function m4ch_http_main(array $args): int
{
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    set_error_handler(static function(int$severity,string$message,string$file,int$line):bool{
        if((error_reporting()&$severity)===0)return false;$GLOBALS['m4c_warning']=['severity'=>$severity,'file'=>basename($file),'line'=>$line];
        $GLOBALS['m4c_failed_assertion']='M4C_H5_HTTP_PHP_WARNING';throw new RuntimeException('M4C_H5_HTTP_PHP_WARNING');});
    try{m4c_expect($args===($_SERVER['argv']??[])&&$args[0]===($_SERVER['SCRIPT_FILENAME']??null)
            &&((count($args)===2&&$args[1]==='serve')||(count($args)===3&&$args[1]==='ingress'
                &&in_array($args[2],M4CH_HTTP_MODES,true))),'M4C_H5_HTTP_FINITE_NATIVE_MODE');
        $GLOBALS['m4c_phase']='source';$source=m4ch_http_source($args[1]==='serve');$GLOBALS['m4ch_http_source']=$source;
        if($args[1]==='serve')return m4ch_http_serve($source);m4ch_http_ingress($args[2],$source);return 0;
    }catch(Throwable$error){$code=$GLOBALS['m4c_failed_assertion']??'';$safe=['schema'=>1,'scope'=>'MS4_CUSTOMER_H5_HTTP_FAILURE_ONLY',
            'status'=>'FAIL_PRESERVED','phase'=>$GLOBALS['m4c_phase']??'source','class'=>get_class($error),
            'error_location'=>['file'=>basename($error->getFile()),'line'=>$error->getLine()],
            'code'=>is_string($code)&&preg_match('/\AM4C_[A-Z0-9_]+\z/D',$code)===1?$code:'M4C_H5_HTTP_UNCLASSIFIED','native_exit'=>1];
        if(isset($GLOBALS['m4c_warning']))$safe['warning']=$GLOBALS['m4c_warning'];fwrite(STDERR,m4cb_json($safe));return 1;}
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if(($argv[1]??'')!=='prepare')exit(m4ch_http_main($argv));
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    set_error_handler(static function(int$severity,string$message,string$file,int$line):bool{
        if((error_reporting()&$severity)===0)return false;
        $GLOBALS['m4c_warning']=['severity'=>$severity,'file'=>basename($file),'line'=>$line];
        $GLOBALS['m4c_failed_assertion']='M4C_PHP_WARNING';throw new RuntimeException('M4C_PHP_WARNING');});
    try{exit(m4ch_entry_run());}
    catch(Throwable$error){$source=$GLOBALS['m4ch_entry_source']??null;$code=$GLOBALS['m4c_failed_assertion']??'';
        $safe=['schema'=>1,'status'=>'FAIL_PRESERVED','scope'=>'NORMAL_CUSTOMER_H5_WITH_MS5_PREREQUISITES_PREPARATION_ONLY',
            'phase'=>$GLOBALS['m4c_phase']??'source','class'=>get_class($error),
            'error_location'=>['file'=>basename($error->getFile()),'line'=>$error->getLine()],
            'code'=>is_string($code)&&preg_match('/\AM4C_[A-Z0-9_]+\z/D',$code)===1?$code:'M4C_UNCLASSIFIED',
            'native_exit'=>1,'HTTP_executed'=>false,'credential_login_authenticated'=>false,'order_creation_executed'=>false,'HTTP_DOM'=>'NOT RUN'];
        if(is_array($source)){foreach(['run_id','source_sha','container_id','freeze_sha256']as$key)$safe[$key]=$source[$key];
            if(isset($GLOBALS['m4c_warning']))$safe['warning']=$GLOBALS['m4c_warning'];
            try{m4c_entry_publish('/run/audit-http-control/prepare-result.json',$safe);}catch(Throwable$ignored){}}
        echo json_encode($safe,JSON_THROW_ON_ERROR)."\n";exit(1);}
}
