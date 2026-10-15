<?php
declare(strict_types=1);

// Import only the unchanged ordinary functions. Its guarded main does not execute in this entry.
require __DIR__.'/ms4-order-normal.php';
require __DIR__.'/lib/ms4-merchant-browser-normal.php';

/** Pure declaration shared with the external profile; not an authorization or runtime receipt. */
function m4mb_source_contract(): array
{
    return ['schema'=>1,'scope'=>'MS4_HTTP_PREPARATION_SOURCE','role'=>'audit-bootstrap',
        'entry'=>'help/audit-regressions/isolated/ms4-merchant-browser-prepare.php',
        'parent_command'=>['/run/ms4-prep/ms4-initialize.php','http-prepare'],
        'source_roots'=>M4O_ROOTS,'source_extra'=>['crmeb/composer.json','crmeb/composer.lock',
            'crmeb/public/index.php','crmeb/public/router.php','crmeb/think',
            'help/audit-regressions/isolated/lib/guard.php','help/audit-regressions/isolated/lib/harness.php',
            'help/audit-regressions/isolated/lib/ms4-cancel-normal.php',
            'help/audit-regressions/isolated/lib/ms4-merchant-browser-normal.php',
            'help/audit-regressions/isolated/lib/ms4-merchant-read-normal.php',
            'help/audit-regressions/isolated/lib/ms4-post-commit-normal.php',
            'help/audit-regressions/isolated/lib/ms4-saved-order-normal.php',
            'help/audit-regressions/isolated/ms4-merchant-browser-prepare.php',
            'help/audit-regressions/isolated/ms4-merchant-http.php',
            'help/audit-regressions/isolated/ms4-order-normal.php'],
        'actors_path'=>'/run/audit-http-browser/merchant-actors.json','actors_max_bytes'=>16384,
        'baseline_path'=>'/run/audit-http-control/sql-baseline.json',
        'oracle_path'=>'/run/audit-http-control/merchant-oracle.json','image_url'=>'/__ms4_fixture__/product.png'];
}

function m4mb_gate_read(string $path): array
{
    m4mb_expect(is_file($path)&&!is_link($path)&&filesize($path)>0&&filesize($path)<=16777216,'BROWSER_GATE_ACTUAL_FILE');
    $value=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    m4mb_expect(is_array($value),'BROWSER_GATE_JSON_OBJECT');return $value;
}

function m4mb_inventory(string $root): array
{
    $contract=m4mb_source_contract();$paths=$contract['source_extra'];
    foreach($contract['source_roots']as$relative){
        $directory=$root.'/'.$relative;m4mb_expect(is_dir($directory)&&!is_link($directory),'BROWSER_SOURCE_ROOT');
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$file){
            m4mb_expect(!$file->isLink(),'BROWSER_NO_SOURCE_SYMLINK');if(!$file->isFile())continue;
            $paths[]=substr($file->getPathname(),strlen($root)+1);
        }
    }
    sort($paths,SORT_STRING);m4mb_expect(count($paths)===count(array_unique($paths,SORT_STRING)),'BROWSER_SOURCE_PATH_CLOSED');
    return $paths;
}

/** The public producer is complete and sealed before this independent DML child starts. */
function m4mb_public(array $receipt,array $identity): void
{
    $keys=array_keys($receipt);sort($keys,SORT_STRING);
    $wanted=['schema','run_id','source_sha','container_id','freeze_sha256','scope','source_count','merchant_count',
        'public_count','files','inputs_unchanged','closed_set_verified','marker_sha256','png_sha256','permissions'];sort($wanted,SORT_STRING);
    m4mb_expect($keys===$wanted&&($receipt['schema']??null)===1&&($receipt['scope']??null)==='MS4_CURRENT_PUBLIC_SNAPSHOT_ONLY'
        &&($receipt['run_id']??null)===$identity['run_id']&&($receipt['source_sha']??null)===$identity['source_sha']
        &&($receipt['container_id']??null)===$identity['container_id']&&($receipt['freeze_sha256']??null)===$identity['freeze_sha256']
        &&($receipt['inputs_unchanged']??null)===true&&($receipt['closed_set_verified']??null)===true,'BROWSER_ACTUAL_PUBLIC_RECEIPT');
    foreach(['source_count','merchant_count','public_count']as$field)
        m4mb_expect(is_int($receipt[$field])&&$receipt[$field]>0,'BROWSER_PUBLIC_REAL_COUNTS');
    $permissions=$receipt['permissions']??null;
    m4mb_expect(is_array($permissions)&&is_int($permissions['publisher_uid']??null)&&$permissions['publisher_uid']>=0
        &&m4mr_same($permissions,['publisher_uid'=>$permissions['publisher_uid'],'directory_mode'=>'0755','file_mode'=>'0644','verified'=>true]),
        'BROWSER_PUBLIC_PERMISSION_PRODUCER');
    $root='/workspace/crmeb/public';$paths=[];$previous='';
    m4mb_expect((fileperms($root)&0777)===0755&&fileowner($root)===$permissions['publisher_uid'],'BROWSER_PUBLIC_ROOT_MODE');
    foreach($receipt['files']as$item){
        $path=$item['path']??null;
        m4mb_expect(is_string($path)&&preg_match('/\A[^\x00-\x20\\\\]+\z/D',$path)===1
            &&$path[0]!=='/'&&!in_array('..',explode('/',$path),true)&&!in_array('.',explode('/',$path),true)
            &&!in_array('',explode('/',$path),true)&&strcmp($path,$previous)>0
            &&is_int($item['bytes']??null)&&$item['bytes']>=0
            &&preg_match('/\A[a-f0-9]{64}\z/D',(string)($item['sha256']??''))===1,'BROWSER_PUBLIC_FILE_DESCRIPTOR');
        $file=$root.'/'.$path;m4mb_expect(is_file($file)&&!is_link($file)&&filesize($file)===$item['bytes']
            &&(fileperms($file)&0777)===0644&&fileowner($file)===$permissions['publisher_uid']
            &&hash_file('sha256',$file)===$item['sha256'],'BROWSER_PUBLIC_ACTUAL_BYTES');$paths[]=$path;$previous=$path;
    }
    $actual=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$file){
        m4mb_expect(!$file->isLink(),'BROWSER_PUBLIC_NO_SYMLINK');
        if($file->isDir())m4mb_expect(($file->getPerms()&0777)===0755&&$file->getOwner()===$permissions['publisher_uid'],
            'BROWSER_PUBLIC_DIRECTORY_MODE');
        if($file->isFile())$actual[]=substr($file->getPathname(),strlen($root)+1);
    }
    sort($actual,SORT_STRING);m4mb_expect($actual===$paths&&count($paths)===$receipt['public_count'],
        'BROWSER_COMPLETE_PUBLIC_CLOSED_SET');
    $png=$root.'/__ms4_fixture__/product.png';
    m4mb_expect(in_array('__ms4_fixture__/product.png',$paths,true)&&in_array('install.lock',$paths,true)
        &&hash_file('sha256',$png)===$receipt['png_sha256']
        &&file_get_contents($png,false,null,0,8)==="\x89PNG\r\n\x1a\n"
        &&hash_file('sha256',$root.'/install.lock')===$receipt['marker_sha256'],'BROWSER_REAL_FROZEN_PNG_AND_MARKER');
}

/** Own native bootstrap/source proof; the original three-role source gate is never weakened. */
function m4mb_source(array &$included): array
{
    $contract=m4mb_source_contract();$run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');
    m4mb_expect(PHP_SAPI==='cli'&&PHP_INT_SIZE>=8&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__
        &&count($_SERVER['argv'])===1&&getcwd()==='/workspace'&&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit',
        'BROWSER_ACTUAL_DML_CHILD_ENTRY');
    m4mb_expect(getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'
        &&getenv('APP_ENV')==='testing'&&getenv('PHP_MARKETPLACE_PAYMENT_TEST_ENABLED')==='true'&&getenv('PHP_CACHE_DRIVER')==='redis'
        &&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run)===1
        &&preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha)===1,'BROWSER_ACTUAL_PUBLIC_ENVIRONMENT');
    $base='/run/audit/evidence/';$createdPath=$base.'http-prepare-created.json';
    $bindingPath=$base.'ms4-http-preparation-source-binding.json';$normalPath=$base.'ms4-order-normal.json';
    $publicPath=$base.'ms4-http-public.json';$freezePath='/run/audit/preparation-source-binding.json';
    $created=m4mb_gate_read($createdPath);$binding=m4mb_gate_read($bindingPath);$normal=m4mb_gate_read($normalPath);
    $public=m4mb_gate_read($publicPath);$freeze=m4mb_gate_read($freezePath);$manifest=audit_manifest();
    $keys=array_keys($binding);sort($keys,SORT_STRING);$wanted=['schema','scope','run_id','source_sha','container_id',
        'native_created_sha256','freeze_sha256','requested_repo_source','requested_run_source','requested_prep_source',
        'entry_sha256','source_files','normal_receipt_sha256','public_receipt_sha256'];sort($wanted,SORT_STRING);
    m4mb_expect($keys===$wanted&&$binding['schema']===1&&$binding['scope']===$contract['scope']
        &&$binding['run_id']===$run&&$binding['source_sha']===$sha&&preg_match('/\A[a-f0-9]{64}\z/D',(string)$binding['container_id'])===1
        &&$binding['native_created_sha256']===hash_file('sha256',$createdPath)&&$binding['freeze_sha256']===hash_file('sha256',$freezePath)
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__)&&$binding['normal_receipt_sha256']===hash_file('sha256',$normalPath)
        &&$binding['public_receipt_sha256']===hash_file('sha256',$publicPath),'BROWSER_ACTUAL_SOURCE_BINDING');
    m4mb_expect(($freeze['source_sha']??null)===$sha&&($freeze['status']??null)==='CLEAN_SOURCE_FROZEN_ONLY'
        &&audit_canonical_host_bind_source($freeze['requested_repo_source']??'')===audit_canonical_host_bind_source($binding['requested_repo_source']),
        'BROWSER_ACTUAL_FROZEN_SOURCE');
    m4mb_expect(($manifest['run_id']??null)===$run&&($manifest['commit_sha']??null)===$sha
        &&($manifest['project']??null)==='tianli-audit-'.$run&&($manifest['guard_mode']??null)==='read-only'
        &&($manifest['isolation']??null)==='ISO-FULL'&&($manifest['network_internal']??null)===true,'BROWSER_ACTUAL_ISOLATION_MANIFEST');
    $roles=array_keys($manifest['services']??[]);sort($roles,SORT_STRING);
    m4mb_expect($roles===['audit-ms4-merchant-http','audit-ms4-order-normal','audit-mysql','audit-redis','audit-ui'],
        'BROWSER_EXACT_FIVE_FINAL_ROLES');
    $ids=[];foreach($manifest['services']as$service){
        m4mb_expect(preg_match('/\A[a-f0-9]{64}\z/D',(string)($service['id']??''))===1
            &&!in_array($service['id'],$ids,true),'BROWSER_DISTINCT_ACTUAL_FINAL_IDENTITIES');$ids[]=$service['id'];
    }
    $volumes=[];foreach(['app-secrets','bootstrap-secrets','fixture-secrets','mysql-data','redis-data','redis-secrets',
        'http-runtime','http-control','http-browser-secrets','http-public']as$suffix)$volumes[]=$manifest['project'].'_'.$suffix;
    sort($volumes,SORT_STRING);$actualVolumes=$manifest['volumes']??[];sort($actualVolumes,SORT_STRING);
    $volumeIdentities=array_keys($manifest['volume_identities']??[]);sort($volumeIdentities,SORT_STRING);
    m4mb_expect($volumes===$actualVolumes&&$volumes===$volumeIdentities,'BROWSER_EXACT_TEN_BOUND_VOLUMES');
    $cid=$binding['container_id'];
    m4mb_expect(($created['id']??null)===$cid&&gethostname()===substr($cid,0,12)&&($created['hostname']??null)===gethostname()
        &&($created['run_id']??null)===$run&&($created['source_sha']??null)===$sha&&($created['image']??null)===M4O_IMAGE
        &&($created['status']??null)==='created'&&($created['pid']??null)===0&&($created['read_only']??null)===true
        &&($created['privileged']??null)===false&&($created['restart_count']??null)===0
        &&($created['entrypoint']??null)===['php']&&($created['command']??null)===$contract['parent_command']
        &&($created['workdir']??null)==='/workspace'&&($created['network_mode']??null)===($manifest['network']['name']??null)
        &&($created['labels']['tianli.audit']??null)==='1'&&($created['labels']['tianli.audit.run']??null)===$run
        &&($created['labels']['com.docker.compose.project']??null)===$manifest['project']
        &&($created['labels']['com.docker.compose.service']??null)===$contract['role']&&($created['cap_drop']??null)===['ALL']
        &&in_array($created['security_options']??null,[['no-new-privileges'],['no-new-privileges:true']],true),
        'BROWSER_NATIVE_CREATED_BOOTSTRAP_IDENTITY');
    m4mb_expect(empty($created['port_bindings']),'BROWSER_BOOTSTRAP_NO_HOST_PORT');
    foreach($created['ports']??[]as$value)m4mb_expect($value===null||$value===[],'BROWSER_BOOTSTRAP_NO_PUBLISHED_PORT');
    m4mb_expect(array_keys($created['networks']??[])===[$manifest['network']['name']],
        'BROWSER_BOOTSTRAP_SINGLE_INTERNAL_NETWORK');
    foreach(['/workspace','/source']as$destination){$mount=m4o_mount($created,$destination);
        m4mb_expect(($mount['Type']??null)==='bind'&&($mount['RW']??null)===false
            &&audit_canonical_host_bind_source($mount['Source'])===audit_canonical_host_bind_source($binding['requested_repo_source']),
            'BROWSER_OWN_FROZEN_SOURCE_MOUNT');}
    foreach(['/run/audit'=>'requested_run_source','/run/ms4-prep'=>'requested_prep_source']as$destination=>$field){
        $mount=m4o_mount($created,$destination);m4mb_expect(($mount['Type']??null)==='bind'
            &&($mount['RW']??null)===($destination==='/run/audit')
            &&audit_canonical_host_bind_source($mount['Source'])===audit_canonical_host_bind_source($binding[$field]),
            'BROWSER_OWN_RUN_PREPARATION_MOUNTS');}
    foreach(['/run/audit-app'=>['app-secrets',false],'/run/audit-http-browser'=>['http-browser-secrets',true],
        '/run/audit-http-control'=>['http-control',true],'/workspace/crmeb/public'=>['http-public',true]]as$destination=>[$suffix,$writable]){
        $mount=m4o_mount($created,$destination);$name=$manifest['project'].'_'.$suffix;
        m4mb_expect(($mount['Type']??null)==='volume'&&($mount['RW']??null)===$writable&&($mount['Name']??null)===$name
            &&in_array($name,$manifest['volumes']??[],true)&&isset($manifest['volume_identities'][$name]),'BROWSER_OWN_PRIVATE_PUBLIC_VOLUME');
    }
    foreach($created['mounts']as$mount)m4mb_expect(!str_contains((string)($mount['Source']??''),'docker.sock')
        &&!str_contains((string)($mount['Destination']??''),'docker.sock'),'BROWSER_NO_DOCKER_SOCKET');
    $tmpfs=false;
    foreach(file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES)as$line){
        $parts=explode(' - ',$line,2);$left=explode(' ',$parts[0]);$right=explode(' ',$parts[1]??'');
        if(($left[4]??'')==='/tmp'&&($right[0]??'')==='tmpfs')$tmpfs=true;
    }
    m4mb_expect(isset($created['tmpfs']['/tmp'])&&$tmpfs&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/.env')&&!file_exists('/source/crmeb/public/install.lock'),
        'BROWSER_PRIVATE_RUNTIME_NO_HOST_ENV');
    $expected=m4mb_inventory('/workspace');m4mb_expect(m4mb_inventory('/source')===$expected,'BROWSER_FULL_SOURCE_PAIR');
    $seen=[];$included=[];
    foreach($binding['source_files']as$item){$path=$item['path']??null;
        m4mb_expect(is_string($path)&&in_array($path,$expected,true)&&!isset($seen[$path])&&is_int($item['bytes']??null)
            &&preg_match('/\A[a-f0-9]{64}\z/D',(string)($item['sha256']??''))===1,'BROWSER_BOUND_SOURCE_DESCRIPTOR');$seen[$path]=true;
        foreach(['/workspace','/source']as$root){$file=$root.'/'.$path;
            m4mb_expect(is_file($file)&&!is_link($file)&&filesize($file)===$item['bytes']&&hash_file('sha256',$file)===$item['sha256'],
                'BROWSER_BOUND_ACTUAL_SOURCE_BYTES');$included[realpath($file)]=true;}
    }
    $actual=array_keys($seen);sort($actual,SORT_STRING);m4mb_expect($actual===$expected,'BROWSER_COMPLETE_SOURCE_CLOSURE');
    m4mb_expect(($normal['schema']??null)===1&&($normal['status']??null)==='VERIFIED_NORMAL_ORDER_INTERNAL'
        &&($normal['scope']??null)==='NORMAL_ORDER_INTERNAL_ONLY'&&($normal['native_exit']??null)===0
        &&($normal['phase']??null)==='final_source'&&($normal['binding']['source_sha']??null)===$sha
        &&($normal['binding']['container_id']??null)===($manifest['services']['audit-ms4-order-normal']['id']??null)
        &&($normal['replay_unchanged']??null)===true&&($normal['merchant_reads']['verified']??null)===true
        &&($normal['merchant_reads']['orders']??null)===['owned_count'=>2,'stopped_count'=>1,'empty_count'=>0],
        'BROWSER_REAL_ORIGINAL_V5_TERMINAL_RECEIPT');
    foreach(['persistent','temporary']as$kind)m4mb_expect(($normal['service_creations'][$kind]['verified']??null)===true
        &&($normal['service_creations'][$kind]['post_commit']['post_commit_done']??null)===1,'BROWSER_ORIGINAL_SERVICE_PROOF');
    foreach(['level','paid']as$kind)m4mb_expect(($normal['membership_creations'][$kind]['verified']??null)===true
        &&($normal['membership_creations'][$kind]['post_commit']['post_commit_done']??null)===1,'BROWSER_ORIGINAL_MEMBERSHIP_PROOF');
    m4mb_expect(($normal['saved_reads']['verified']??null)===true&&($normal['cancellations']['verified']??null)===true
        &&($normal['post_commit_schedule']['verified']??null)===true,'BROWSER_ORIGINAL_COMPLETE_CHAIN_PROOF');
    $identity=['run_id'=>$run,'source_sha'=>$sha,'container_id'=>$cid,'freeze_sha256'=>$binding['freeze_sha256']];
    m4mb_public($public,$identity);
    return ['container_id'=>$cid,'source_sha'=>$sha,'freeze_sha256'=>$binding['freeze_sha256'],
        'native_created_sha256'=>hash_file('sha256',$createdPath),'source_binding_sha256'=>hash_file('sha256',$bindingPath),
        'normal_receipt_sha256'=>hash_file('sha256',$normalPath),'public_receipt_sha256'=>hash_file('sha256',$publicPath),
        'image_url'=>$contract['image_url']];
}

function m4mb_main(): int
{
    $phase='source';$GLOBALS['m4o_phase']=&$phase;$included=[];$runtimeWarning=null;$warningOrigin=null;
    $result=['schema'=>1,'status'=>'FAIL','scope'=>'NORMAL_MERCHANT_BROWSER_PREPARATION_ONLY',
        'run_id'=>getenv('AUDIT_RUN_ID'),'source_sha'=>getenv('AUDIT_SOURCE_SHA'),'binding'=>null,
        'private_credentials_emitted'=>false,'HTTP_DOM_acceptance'=>'NOT RUN'];
    try{
        ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
        set_error_handler(static function(int $severity,string $unusedMessage,string $file,int $line)use(&$runtimeWarning,&$warningOrigin):never{
            $warningOrigin=['severity'=>$severity,'file'=>basename($file),'line'=>$line,'reported'=>(error_reporting()&$severity)!==0];
            $runtimeWarning=new RuntimeException('PHP_RUNTIME_WARNING');throw $runtimeWarning;
        });
        $binding=m4mb_source($included);$result['binding']=$binding;audit_guard_assert();
        $phase='actual_prerequisites';$self=m4o_prerequisites();$phase='actual_app';$app=m4o_app();
        $marketplace=$app->config->get('marketplace');$marketplace['enabled']=true;$marketplace['self_operated_mer_id']=$self;
        $app->config->set($marketplace,'marketplace');
        $phase='actual_http_fixtures';$normal=m4mb_gate_read('/run/audit/evidence/ms4-order-normal.json');
        $result+=m4mb_prepare($app,audit_db(),$normal,$binding);
        $phase='final_source';$final=[];m4mb_expect(m4mb_source($final)===$binding&&$final===$included,'BROWSER_FINAL_SOURCE_UNCHANGED');
        foreach(get_included_files()as$file)m4mb_expect(isset($included[realpath($file)]),'BROWSER_INCLUDED_SOURCE_CLOSED');
        audit_guard_assert();m4mb_expect($app->config->get('marketplace.trade.enabled')===false,'BROWSER_FINAL_CREATE_GATE_CLOSED');
        $result['status']='VERIFIED_MERCHANT_BROWSER_PREPARATION_ONLY';
    }catch(Throwable $error){$isRuntimeWarning=$error===$runtimeWarning;
        $result['diagnostic']=['class'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine(),
            'assertion_code'=>$isRuntimeWarning?'PHP_RUNTIME_WARNING':($GLOBALS['m4o_failed_assertion']??'UNCLASSIFIED_BROWSER_PREPARATION_FAILURE')];
        if($isRuntimeWarning)$result['diagnostic']['warning_origin']=$warningOrigin;
    }
    $result['phase']=$phase;$result['assertions']=$GLOBALS['m4o_assertions']??0;
    $result['native_exit']=$result['status']==='VERIFIED_MERCHANT_BROWSER_PREPARATION_ONLY'?0:1;
    try{m4o_new('/run/audit/evidence/ms4-merchant-browser-prepare.json',$result);}
    catch(Throwable $error){echo m4o_json(['status'=>'FAIL','scope'=>$result['scope'],'native_exit'=>1,'phase'=>'receipt_write']);return 1;}
    echo m4o_json(['status'=>$result['status'],'scope'=>$result['scope'],'native_exit'=>$result['native_exit'],'phase'=>$phase,
        'evidence_ref'=>'evidence/ms4-merchant-browser-prepare.json','HTTP_DOM_acceptance'=>'NOT RUN']);
    return $result['native_exit'];
}

if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(m4mb_main());
