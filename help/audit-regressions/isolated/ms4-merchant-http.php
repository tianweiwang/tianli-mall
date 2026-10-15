<?php
declare(strict_types=1);

/**
 * Dedicated ordinary HTTP role. No business actor credentials or test authentication.
 * Native entry: php help/audit-regressions/isolated/ms4-merchant-http.php serve
 * Private entry: the same file, ingress runtime|plan|command|supervisor|write|finish|audit.
 * Ingress write reads only {kind,payload} from stdin; it never accepts a filename.
 * Shared identities: run_id/source_sha/container_id/freeze_sha256/epoch/step_nonce/
 * not_before/deadline. Actor arguments are labels, never account/password/JWT.
 */
require_once __DIR__.'/lib/harness.php';
ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');
umask(0077);

const M4H_CONTROL='/run/audit-http-control';
const M4H_ROLE='audit-ms4-merchant-http';
const M4H_IMAGE='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';
const M4H_ROOTS=['crmeb/app','crmeb/crmeb','crmeb/vendor','crmeb/config'];
const M4H_EXTRA=['crmeb/composer.json','crmeb/composer.lock','crmeb/public/index.php','crmeb/public/router.php','crmeb/think',
    'help/audit-regressions/isolated/lib/guard.php','help/audit-regressions/isolated/lib/harness.php',
    'help/audit-regressions/isolated/lib/ms4-cancel-normal.php','help/audit-regressions/isolated/lib/ms4-merchant-browser-normal.php',
    'help/audit-regressions/isolated/lib/ms4-merchant-read-normal.php','help/audit-regressions/isolated/lib/ms4-post-commit-normal.php',
    'help/audit-regressions/isolated/lib/ms4-saved-order-normal.php',
    'help/audit-regressions/isolated/ms4-merchant-browser-prepare.php','help/audit-regressions/isolated/ms4-merchant-http.php',
    'help/audit-regressions/isolated/ms4-order-normal.php'];
const M4H_IDENTITIES=['run_id','source_sha','container_id','freeze_sha256','epoch','step_nonce','not_before','deadline'];
const M4H_ACTIONS=['login','list','page','filter','detail','return','refresh','home','logout','visual'];
// Source-bound ordinary coverage; placeholders are resolved only from the native saved oracle.
const M4H_PLAN_RULES=[
    ['key'=>'owned-login','actor_label'=>'owned','action'=>'login','args'=>[],'capture'=>['method'=>'POST','path'=>'/merchantapi/login','query'=>[]]],
    ['key'=>'owned-list','actor_label'=>'owned','action'=>'list','args'=>[],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'owned-list-320','actor_label'=>'owned','action'=>'visual','args'=>['scene'=>'owned-list-320','viewport'=>['width'=>320,'height'=>900]],'capture'=>null],
    ['key'=>'owned-list-390','actor_label'=>'owned','action'=>'visual','args'=>['scene'=>'owned-list-390','viewport'=>['width'=>390,'height'=>900]],'capture'=>null],
    ['key'=>'owned-list-1440','actor_label'=>'owned','action'=>'visual','args'=>['scene'=>'owned-list-1440','viewport'=>['width'=>1440,'height'=>900]],'capture'=>null],
    ['key'=>'owned-page2','actor_label'=>'owned','action'=>'page','args'=>['direction'=>'next','page'=>2],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>2,'limit'=>20]]],
    ['key'=>'owned-page1','actor_label'=>'owned','action'=>'page','args'=>['direction'=>'prev','page'=>1],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'owned-full-number','actor_label'=>'owned','action'=>'filter','args'=>['order_id'=>'$N','paid_label'=>'全部','cancel_label'=>'全部'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20,'order_id'=>'$N']]],
    ['key'=>'owned-unpaid-open','actor_label'=>'owned','action'=>'filter','args'=>['order_id'=>'','paid_label'=>'未支付','cancel_label'=>'未取消'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20,'paid'=>0,'is_cancel'=>0]]],
    ['key'=>'owned-cancelled','actor_label'=>'owned','action'=>'filter','args'=>['order_id'=>'','paid_label'=>'全部','cancel_label'=>'已取消'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20,'is_cancel'=>1]]],
    ['key'=>'owned-paid-empty','actor_label'=>'owned','action'=>'filter','args'=>['order_id'=>'','paid_label'=>'已支付','cancel_label'=>'全部'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20,'paid'=>1]]],
    ['key'=>'owned-reset','actor_label'=>'owned','action'=>'filter','args'=>['order_id'=>'','paid_label'=>'全部','cancel_label'=>'全部'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'owned-detail','actor_label'=>'owned','action'=>'detail','args'=>['order_id'=>'$N'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders/$P','query'=>[]]],
    ['key'=>'owned-detail-refresh','actor_label'=>'owned','action'=>'refresh','args'=>['heading'=>'订单详情','order_id'=>'$N'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders/$P','query'=>[]]],
    ['key'=>'owned-detail-320','actor_label'=>'owned','action'=>'visual','args'=>['scene'=>'owned-detail-320','viewport'=>['width'=>320,'height'=>900]],'capture'=>null],
    ['key'=>'owned-detail-390','actor_label'=>'owned','action'=>'visual','args'=>['scene'=>'owned-detail-390','viewport'=>['width'=>390,'height'=>900]],'capture'=>null],
    ['key'=>'owned-detail-1440','actor_label'=>'owned','action'=>'visual','args'=>['scene'=>'owned-detail-1440','viewport'=>['width'=>1440,'height'=>900]],'capture'=>null],
    ['key'=>'owned-return','actor_label'=>'owned','action'=>'return','args'=>[],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'owned-list-refresh','actor_label'=>'owned','action'=>'refresh','args'=>['heading'=>'订单列表'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'owned-home','actor_label'=>'owned','action'=>'home','args'=>[],'capture'=>['method'=>'GET','path'=>'/merchantapi/me','query'=>[]]],
    ['key'=>'owned-logout','actor_label'=>'owned','action'=>'logout','args'=>[],'capture'=>['method'=>'POST','path'=>'/merchantapi/logout','query'=>[]]],
    ['key'=>'stopped-login','actor_label'=>'stopped','action'=>'login','args'=>[],'capture'=>['method'=>'POST','path'=>'/merchantapi/login','query'=>[]]],
    ['key'=>'stopped-list','actor_label'=>'stopped','action'=>'list','args'=>[],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'stopped-detail','actor_label'=>'stopped','action'=>'detail','args'=>['order_id'=>'$S'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders/$Q','query'=>[]]],
    ['key'=>'stopped-return','actor_label'=>'stopped','action'=>'return','args'=>[],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'stopped-logout','actor_label'=>'stopped','action'=>'logout','args'=>[],'capture'=>['method'=>'POST','path'=>'/merchantapi/logout','query'=>[]]],
    ['key'=>'empty-login','actor_label'=>'empty','action'=>'login','args'=>[],'capture'=>['method'=>'POST','path'=>'/merchantapi/login','query'=>[]]],
    ['key'=>'empty-list','actor_label'=>'empty','action'=>'list','args'=>[],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'empty-list-320','actor_label'=>'empty','action'=>'visual','args'=>['scene'=>'empty-list-320','viewport'=>['width'=>320,'height'=>900]],'capture'=>null],
    ['key'=>'empty-list-390','actor_label'=>'empty','action'=>'visual','args'=>['scene'=>'empty-list-390','viewport'=>['width'=>390,'height'=>900]],'capture'=>null],
    ['key'=>'empty-list-1440','actor_label'=>'empty','action'=>'visual','args'=>['scene'=>'empty-list-1440','viewport'=>['width'=>1440,'height'=>900]],'capture'=>null],
    ['key'=>'empty-list-refresh','actor_label'=>'empty','action'=>'refresh','args'=>['heading'=>'订单列表'],'capture'=>['method'=>'GET','path'=>'/merchantapi/orders','query'=>['page'=>1,'limit'=>20]]],
    ['key'=>'empty-home','actor_label'=>'empty','action'=>'home','args'=>[],'capture'=>['method'=>'GET','path'=>'/merchantapi/me','query'=>[]]],
    ['key'=>'empty-logout','actor_label'=>'empty','action'=>'logout','args'=>[],'capture'=>['method'=>'POST','path'=>'/merchantapi/logout','query'=>[]]],
];


function m4h_assert(bool $ok,string $code): void
{
    if(!$ok)throw new RuntimeException($code);
}
function m4h_keys(array $value,array $expected): void
{
    $keys=array_keys($value);sort($keys,SORT_STRING);sort($expected,SORT_STRING);
    m4h_assert($keys===$expected,'M4H_CLOSED_KEYS');
}
function m4h_json(array $value): string
{
    // Only these wire contracts contain a query map that must remain an object even when empty.
    // Cast a copy; strict internal comparisons continue using arrays.
    if(($value['scope']??null)==='MS4_MERCHANT_BROWSER_COMMAND'&&is_array($value['capture']??null))
        $value['capture']['query']=(object)$value['capture']['query'];
    elseif(($value['scope']??null)==='MS4_MERCHANT_BROWSER_PLAN')
        foreach($value['steps'] as &$step)if(is_array($step['command']['capture']??null))
            $step['command']['capture']['query']=(object)$step['command']['capture']['query'];
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}
function m4h_read(string $path,int $limit=8388608): array
{
    clearstatcache(true,$path);$stat=lstat($path);
    m4h_assert(is_array($stat)&&is_file($path)&&!is_link($path)&&$stat['size']>0&&$stat['size']<=$limit,'M4H_REGULAR_JSON');
    $raw=file_get_contents($path);m4h_assert(is_string($raw)&&strlen($raw)===$stat['size'],'M4H_COMPLETE_JSON');
    $value=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
    m4h_assert(is_array($value)&&!array_is_list($value),'M4H_JSON_OBJECT');return $value;
}
function m4h_directory(string $path): array
{
    clearstatcache(true,$path);$stat=lstat($path);
    m4h_assert(is_array($stat)&&is_dir($path)&&!is_link($path)&&realpath($path)===$path
        &&$stat['mode']===0040700&&$stat['uid']===posix_geteuid(),'M4H_PRIVATE_DIRECTORY');
    return array_intersect_key($stat,array_flip(['dev','ino','mode','uid','gid']));
}
function m4h_private_meta(string $path): array
{
    m4h_assert(str_starts_with($path,M4H_CONTROL.'/')&&!str_contains($path,'/../'),'M4H_PRIVATE_ROOT');
    m4h_directory(dirname($path));clearstatcache(true,$path);$stat=lstat($path);
    m4h_assert(is_array($stat)&&is_file($path)&&!is_link($path)&&realpath($path)===$path
        &&$stat['mode']===0100600&&$stat['uid']===posix_geteuid(),'M4H_PRIVATE_FILE');
    return ['bytes'=>$stat['size'],'sha256'=>hash_file('sha256',$path),'dev'=>$stat['dev'],'ino'=>$stat['ino'],
        'mode'=>'0600','uid'=>$stat['uid']];
}
function m4h_private_read(string $path,int $limit=2097152): array
{
    $before=m4h_private_meta($path);m4h_assert($before['bytes']<=$limit,'M4H_PRIVATE_LIMIT');
    $value=m4h_read($path,$limit);m4h_assert(m4h_private_meta($path)===$before,'M4H_PRIVATE_READ_IDENTITY');return $value;
}
function m4h_publish(string $path,array|string $value): array
{
    m4h_assert(str_starts_with($path,M4H_CONTROL.'/')&&!str_contains($path,'/../'),'M4H_PUBLISH_ROOT');
    m4h_directory(dirname($path));m4h_assert(!file_exists($path)&&!is_link($path),'M4H_CREATE_ONLY');
    $bytes=is_array($value)?m4h_json($value):$value;$temporary=$path.'.tmp-'.bin2hex(random_bytes(12));
    $stream=fopen($temporary,'xb');m4h_assert(is_resource($stream)&&chmod($temporary,0600),'M4H_PRIVATE_OPEN');
    try{
        $offset=0;while($offset<strlen($bytes)){$written=fwrite($stream,substr($bytes,$offset));
            m4h_assert(is_int($written)&&$written>0,'M4H_PRIVATE_WRITE');$offset+=$written;}
        m4h_assert(fflush($stream)&&fsync($stream),'M4H_PRIVATE_FLUSH');
    }finally{fclose($stream);}
    $meta=m4h_private_meta($temporary);
    m4h_assert($meta['bytes']===strlen($bytes)&&$meta['sha256']===hash('sha256',$bytes),'M4H_PRIVATE_FULL_BYTES');
    m4h_assert(link($temporary,$path)&&m4h_private_meta($path)===$meta,'M4H_ATOMIC_CREATE_ONLY');
    m4h_assert(m4h_private_meta($temporary)===$meta&&unlink($temporary),'M4H_REMOVE_ONLY_OWN_TEMP');return $meta;
}
function m4h_mount(array $facts,string $destination): array
{
    $rows=array_values(array_filter($facts['mounts']??[],static fn(array $row):bool=>($row['Destination']??'')===$destination));
    m4h_assert(count($rows)===1,'M4H_ONE_MOUNT');return $rows[0];
}
function m4h_inventory(string $root): array
{
    $paths=M4H_EXTRA;
    foreach(M4H_ROOTS as $directory){
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){m4h_assert(!$file->isLink(),'M4H_SOURCE_NO_LINK');
            if($file->isFile())$paths[]=substr($file->getPathname(),strlen($root)+1);}
    }
    sort($paths,SORT_STRING);m4h_assert(count($paths)===count(array_unique($paths,SORT_STRING)),'M4H_SOURCE_UNIQUE');return $paths;
}

/** Dedicated five-role proof; the old three-role normal entry remains untouched. */
function m4h_source(bool $full=true): array
{
    $run=getenv('AUDIT_RUN_ID');$sha=getenv('AUDIT_SOURCE_SHA');$base='/run/audit/evidence/';
    m4h_assert(PHP_SAPI==='cli'&&PHP_INT_SIZE>=8&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__
        &&audit_repo_dir()==='/workspace'&&audit_run_dir()==='/run/audit','M4H_NATIVE_ENTRY');
    m4h_assert(getenv('AUDIT_ISOLATED')==='1'&&getenv('AUDIT_GUARD_MODE')==='read-only'
        &&preg_match('/\A\d{14}[a-f0-9]{6}\z/D',(string)$run)===1
        &&preg_match('/\A[a-f0-9]{40}\z/D',(string)$sha)===1,'M4H_ISOLATED_ENV');
    $manifest=audit_manifest();$created=m4h_read($base.'http-created.json');
    $binding=m4h_read($base.'ms4-merchant-http-source-binding.json');
    m4h_keys($binding,['schema','scope','run_id','source_sha','container_id','native_created_sha256','freeze_sha256',
        'requested_repo_source','requested_run_source','requested_prep_source','entry_sha256','source_files',
        'normal_receipt_sha256','public_receipt_sha256']);
    $roles=array_keys($manifest['services']??[]);sort($roles,SORT_STRING);
    m4h_assert($roles===['audit-ms4-merchant-http','audit-ms4-order-normal','audit-mysql','audit-redis','audit-ui']
        &&($manifest['run_id']??'')===$run&&($manifest['commit_sha']??'')===$sha
        &&($manifest['project']??'')==='tianli-audit-'.$run&&($manifest['isolation']??'')==='ISO-FULL'
        &&($manifest['guard_mode']??'')==='read-only'&&($manifest['network_internal']??null)===true,'M4H_FINAL_MANIFEST');
    $service=$manifest['services'][M4H_ROLE];$cid=$service['id']??'';
    m4h_assert(preg_match('/\A[a-f0-9]{64}\z/D',(string)$cid)===1&&gethostname()===substr($cid,0,12)
        &&($binding['schema']??null)===1&&($binding['scope']??'')==='MS4_MERCHANT_HTTP_SOURCE'
        &&$binding['run_id']===$run&&$binding['source_sha']===$sha&&$binding['container_id']===$cid
        &&$binding['native_created_sha256']===hash_file('sha256',$base.'http-created.json')
        &&$binding['freeze_sha256']===hash_file('sha256','/run/audit/preparation-source-binding.json')
        &&$binding['entry_sha256']===hash_file('sha256',__FILE__),'M4H_CURRENT_SOURCE_BINDING');
    m4h_assert(($created['run_id']??'')===$run&&($created['source_sha']??'')===$sha&&($created['id']??'')===$cid
        &&($created['hostname']??'')===gethostname()&&($created['proof_name']??'')==='http'
        &&($created['image']??'')===M4H_IMAGE&&($service['image_id']??'')===M4H_IMAGE
        &&($created['status']??'')==='created'&&($created['read_only']??null)===true&&($created['privileged']??null)===false
        &&($created['entrypoint']??null)===['php']
        &&($created['command']??null)===['help/audit-regressions/isolated/ms4-merchant-http.php','serve']
        &&($created['workdir']??'')==='/workspace'&&($created['restart_count']??null)===0
        &&($created['cap_drop']??null)===['ALL']
        &&in_array($created['security_options']??null,[['no-new-privileges'],['no-new-privileges:true']],true)
        &&($created['network_mode']??'')===($manifest['network']['name']??null),'M4H_CREATED_ROLE');
    foreach(['tianli.audit'=>'1','tianli.audit.run'=>$run,'com.docker.compose.project'=>$manifest['project'],
        'com.docker.compose.service'=>M4H_ROLE] as $key=>$value)
        m4h_assert(($created['labels'][$key]??null)===$value,'M4H_NATIVE_LABELS');
    m4h_assert(empty($created['port_bindings'])&&count($created['networks']??[])===1
        &&isset($created['networks'][$manifest['network']['name']]),'M4H_INTERNAL_NO_PORT');
    foreach($created['ports']??[] as $port)m4h_assert($port===null||$port===[],'M4H_NO_HOST_PORT');
    $destinations=[];
    foreach(['/workspace'=>'requested_repo_source','/source'=>'requested_repo_source','/run/audit'=>'requested_run_source',
        '/run/ms4-prep'=>'requested_prep_source'] as $destination=>$key){
        $mount=m4h_mount($created,$destination);$destinations[]=$destination;
        m4h_assert(($mount['Type']??'')==='bind'&&($mount['RW']??null)===false
            &&audit_canonical_host_bind_source($mount['Source'])===audit_canonical_host_bind_source($binding[$key]),'M4H_BOUND_SOURCE_RO');
    }
    foreach(['/run/audit-app'=>['app-secrets',false],'/workspace/crmeb/runtime'=>['http-runtime',true],
        '/workspace/crmeb/public'=>['http-public',false],M4H_CONTROL=>['http-control',true]] as $destination=>$spec){
        $mount=m4h_mount($created,$destination);$destinations[]=$destination;$name=$manifest['project'].'_'.$spec[0];
        m4h_assert(($mount['Type']??'')==='volume'&&($mount['RW']??null)===$spec[1]&&($mount['Name']??'')===$name
            &&in_array($name,$manifest['volumes']??[],true)&&isset($manifest['volume_identities'][$name]),'M4H_OWN_VOLUME');
    }
    $actual=array_column($created['mounts']??[],'Destination');sort($actual,SORT_STRING);sort($destinations,SORT_STRING);
    m4h_assert($actual===$destinations&&count($manifest['volumes']??[])===10
        &&count(array_unique($manifest['volumes'],SORT_STRING))===10,'M4H_EXACT_ROLE_MOUNTS_AND_TEN_VOLUMES');
    foreach($created['mounts'] as $mount)m4h_assert(!str_contains((string)($mount['Source']??''),'docker.sock')
        &&!str_contains((string)($mount['Destination']??''),'docker.sock')
        &&!in_array($mount['Destination']??'',['/run/audit-bootstrap','/run/audit-http-browser'],true),'M4H_NO_PRIVILEGED_OR_ACTOR_MOUNT');
    m4h_assert(isset($created['tmpfs']['/tmp'])&&!file_exists('/source/crmeb/.env')&&!file_exists('/workspace/crmeb/.env')
        &&!file_exists('/source/crmeb/public/install.lock')&&is_file('/workspace/crmeb/public/install.lock'),'M4H_SEPARATE_INSTALLED_PUBLIC');
    $tmpfs=false;foreach(file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES) as $line){
        $parts=explode(' - ',$line,2);$left=explode(' ',$parts[0]);$right=explode(' ',$parts[1]??'');
        if(($left[4]??'')==='/tmp'&&($right[0]??'')==='tmpfs')$tmpfs=true;}
    m4h_assert($tmpfs,'M4H_ACTUAL_TMPFS');
    $expected=$full?m4h_inventory('/source'):array_column($binding['source_files'],'path');
    if($full)m4h_assert(m4h_inventory('/workspace')===$expected,'M4H_SAME_SOURCE_CLOSURE');
    $seen=[];$included=[];$bound=[];
    foreach($binding['source_files'] as $row){$path=$row['path']??'';
        m4h_assert(is_string($path)&&in_array($path,$expected,true)&&!isset($seen[$path])&&is_int($row['bytes']??null)
            &&preg_match('/\A[a-f0-9]{64}\z/D',(string)($row['sha256']??''))===1,'M4H_BOUND_FILE');$seen[$path]=true;
        foreach(['/source','/workspace'] as $root){$file=$root.'/'.$path;
            if(!$full){$included[$file]=true;$bound[$file]=$row;continue;}
            if($full||in_array(realpath($file),get_included_files(),true))
                m4h_assert(is_file($file)&&!is_link($file)&&filesize($file)===$row['bytes']
                    &&hash_file('sha256',$file)===$row['sha256'],'M4H_FULL_SOURCE_BYTES');
            $included[realpath($file)]=true;}
    }
    $paths=array_keys($seen);sort($paths,SORT_STRING);m4h_assert($paths===$expected,'M4H_COMPLETE_SOURCE_BINDING');
    if(!$full){$checked=[];
        foreach(get_included_files() as $path){$file=realpath($path);
            m4h_assert(is_string($file)&&isset($bound[$file]),'M4H_INCLUDED_SOURCE');$row=$bound[$file];
            m4h_assert(is_file($file)&&!is_link($file)&&filesize($file)===$row['bytes']
                &&hash_file('sha256',$file)===$row['sha256'],'M4H_FULL_SOURCE_BYTES');$checked[$file]=true;}
    }
    foreach(get_included_files() as $path){$file=realpath($path);
        m4h_assert(is_string($file)&&isset($included[$file])&&($full||isset($checked[$file])),'M4H_INCLUDED_SOURCE');}
    m4h_assert($binding['normal_receipt_sha256']===hash_file('sha256',$base.'ms4-order-normal.json')
        &&$binding['public_receipt_sha256']===hash_file('sha256',$base.'ms4-http-public.json'),'M4H_PRECONDITION_RECEIPTS');
    $normal=m4h_read($base.'ms4-order-normal.json');
    m4h_assert(($normal['binding']['source_sha']??'')===$sha
        &&($normal['binding']['container_id']??'')===($manifest['services']['audit-ms4-order-normal']['id']??null)
        &&($normal['phase']??'')==='final_source'&&($normal['scope']??'')==='NORMAL_ORDER_INTERNAL_ONLY'
        &&($normal['status']??'')==='VERIFIED_NORMAL_ORDER_INTERNAL'&&($normal['native_exit']??null)===0,'M4H_ORIGINAL_NORMAL_TERMINAL_ZERO');
    $public=m4h_read($base.'ms4-http-public.json');
    m4h_assert(($public['schema']??null)===1&&($public['run_id']??'')===$run&&($public['source_sha']??'')===$sha
        &&($public['scope']??'')==='MS4_CURRENT_PUBLIC_SNAPSHOT_ONLY'&&($public['freeze_sha256']??'')===$binding['freeze_sha256']
        &&($public['inputs_unchanged']??null)===true&&($public['closed_set_verified']??null)===true,'M4H_ACTUAL_PUBLIC_COPY');
    m4h_keys($public,['schema','run_id','source_sha','container_id','freeze_sha256','scope','source_count','merchant_count',
        'public_count','files','inputs_unchanged','closed_set_verified','marker_sha256','png_sha256','permissions']);
    m4h_assert(($public['permissions']['directory_mode']??'')==='0755'&&($public['permissions']['file_mode']??'')==='0644'
        &&($public['permissions']['verified']??null)===true&&is_int($public['permissions']['publisher_uid']??null),'M4H_PUBLIC_PERMISSIONS_PROOF');
    m4h_keys($public['permissions'],['publisher_uid','directory_mode','file_mode','verified']);
    $files=[];foreach($public['files']??[] as $row){$relative=$row['path']??'';
        m4h_assert(is_string($relative)&&$relative!==''&&!str_starts_with($relative,'/')&&!str_contains($relative,'\\')
            &&!in_array('..',explode('/',$relative),true)&&!isset($files[$relative]),'M4H_PUBLIC_RELATIVE_PATH');
        $file='/workspace/crmeb/public/'.$relative;
        if($full){$stat=lstat($file);
            m4h_assert(is_array($stat)&&is_file($file)&&!is_link($file)&&is_int($row['bytes']??null)&&filesize($file)===$row['bytes']
                &&hash_file('sha256',$file)===($row['sha256']??null)&&($stat['mode']&0777)===0644
                &&$stat['uid']===$public['permissions']['publisher_uid'],'M4H_PUBLIC_BYTES');}
        $files[$relative]=true;}
    if($full){
        $rootStat=lstat('/workspace/crmeb/public');
        m4h_assert(is_array($rootStat)&&($rootStat['mode']&0777)===0755
            &&$rootStat['uid']===$public['permissions']['publisher_uid'],'M4H_PUBLIC_ROOT_PERMISSIONS');
        $actual=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/workspace/crmeb/public',FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as $file){m4h_assert(!$file->isLink(),'M4H_PUBLIC_NO_LINK');
            if($file->isFile())$actual[]=substr($file->getPathname(),strlen('/workspace/crmeb/public/'));
            elseif($file->isDir())m4h_assert(($file->getPerms()&0777)===0755
                &&$file->getOwner()===$public['permissions']['publisher_uid'],'M4H_PUBLIC_DIRECTORY_MODE');}
        sort($actual,SORT_STRING);$wanted=array_keys($files);sort($wanted,SORT_STRING);
        m4h_assert($actual===$wanted,'M4H_PUBLIC_EXACT_PATHS');
    }
    m4h_assert(count($files)===($public['public_count']??null)
        &&isset($files['index.php'],$files['router.php'],$files['install.lock'],$files['__ms4_fixture__/product.png'])
        &&hash_file('sha256','/workspace/crmeb/public/install.lock')===$public['marker_sha256']
        &&hash_file('sha256','/workspace/crmeb/public/__ms4_fixture__/product.png')===$public['png_sha256'],'M4H_COMPLETE_PUBLIC_CLOSURE');
    $preparation=m4h_read($base.'ms4-merchant-browser-prepare.json');
    m4h_assert(($preparation['schema']??null)===1&&($preparation['run_id']??'')===$run&&($preparation['source_sha']??'')===$sha
        &&($preparation['scope']??'')==='NORMAL_MERCHANT_BROWSER_PREPARATION_ONLY'
        &&($preparation['status']??'')==='VERIFIED_MERCHANT_BROWSER_PREPARATION_ONLY'
        &&($preparation['phase']??'')==='final_source'&&($preparation['native_exit']??null)===0
        &&($preparation['private_credentials_emitted']??null)===false
        &&($preparation['orders']['created']??null)===20&&($preparation['orders']['owned_count']??null)===22
        &&($preparation['orders']['stopped_count']??null)===1&&($preparation['orders']['empty_count']??null)===0,
        'M4H_NATIVE_HTTP_PREPARATION_COMPLETE');
    $prepared=$preparation['binding']??[];m4h_assert(is_array($prepared),'M4H_PREPARATION_BINDING_OBJECT');
    $identity=['run_id'=>$run,'source_sha'=>$sha,'container_id'=>$cid,'freeze_sha256'=>$binding['freeze_sha256'],
        'public_receipt_sha256'=>$binding['public_receipt_sha256']];
    m4h_prepare_binding($prepared,$identity);
    m4h_assert(($public['container_id']??null)===$prepared['container_id'],'M4H_PUBLIC_SAME_BOOTSTRAP_CID');
    audit_guard_assert();m4h_directory(M4H_CONTROL);
    return ['run_id'=>$run,'source_sha'=>$sha,'container_id'=>$cid,'freeze_sha256'=>$binding['freeze_sha256'],
        'source_binding_sha256'=>hash_file('sha256',$base.'ms4-merchant-http-source-binding.json'),
        'native_created_sha256'=>$binding['native_created_sha256'],'public_receipt_sha256'=>$binding['public_receipt_sha256'],
        'included'=>$included];
}

function m4h_identity(array $value,array $source,?array $runtime=null): void
{
    m4h_assert(($value['schema']??null)===1,'M4H_FRAME_SCHEMA');
    foreach(['run_id','source_sha','container_id','freeze_sha256'] as $key)
        m4h_assert(($value[$key]??null)===$source[$key],'M4H_FRAME_SOURCE');
    m4h_assert(preg_match('/\A[a-f0-9]{64}\z/D',(string)($value['epoch']??''))===1
        &&preg_match('/\A[a-f0-9]{48}\z/D',(string)($value['step_nonce']??''))===1
        &&is_int($value['not_before']??null)&&is_int($value['deadline']??null)
        &&$value['not_before']<=time()&&time()<=$value['deadline']&&$value['deadline']>=$value['not_before'],
        'M4H_LIVE_FRAME');
    if($runtime!==null)m4h_assert($value['epoch']===$runtime['epoch']
        &&$value['not_before']>=$runtime['not_before']&&$value['deadline']<=$runtime['deadline'],'M4H_RUNTIME_EPOCH_WINDOW');
}
function m4h_runtime(array $source): array
{
    $runtime=m4h_private_read(M4H_CONTROL.'/runtime.json',16384);
    m4h_keys($runtime,array_merge(['schema','scope','origin'],M4H_IDENTITIES));
    m4h_identity($runtime,$source);m4h_assert($runtime['scope']==='MS4_MERCHANT_HTTP_RUNTIME'
        &&$runtime['origin']==='http://127.0.0.1:18974','M4H_BOUND_BROWSER_RUNTIME');return $runtime;
}
function m4h_header(array $frame,string $scope): array
{
    return ['schema'=>1,'scope'=>$scope]+array_intersect_key($frame,array_flip(M4H_IDENTITIES));
}
function m4h_active(string $name,string $directory,array $source,array $runtime): array
{
    $pointer=m4h_private_read(M4H_CONTROL.'/'.$name,16384);
    m4h_keys($pointer,array_merge(['schema','scope','payload_meta'],M4H_IDENTITIES));m4h_identity($pointer,$source,$runtime);
    m4h_assert($pointer['scope']==='MS4_MERCHANT_HTTP_ACTIVE','M4H_ACTIVE_POINTER_SCOPE');
    $path=M4H_CONTROL.'/'.$directory.'/'.$pointer['step_nonce'].'.json';
    m4h_assert(m4h_private_meta($path)===$pointer['payload_meta'],'M4H_ACTIVE_SAME_FILE');
    $value=m4h_private_read($path);m4h_identity($value,$source,$runtime);
    foreach(M4H_IDENTITIES as $key)m4h_assert($value[$key]===$pointer[$key],'M4H_ACTIVE_SAME_IDENTITIES');return $value;
}
function m4h_activate(string $name,string $directory,array $payload): void
{
    $path=M4H_CONTROL.'/'.$directory.'/'.$payload['step_nonce'].'.json';$meta=m4h_publish($path,$payload);
    $active=M4H_CONTROL.'/'.$name;
    if(file_exists($active)){$old=m4h_private_meta($active);$previous=m4h_private_read($active,16384);
        foreach(['run_id','source_sha','container_id','freeze_sha256','epoch'] as $key)
            m4h_assert(($previous[$key]??null)===$payload[$key],'M4H_REPLACE_ONLY_SAME_RUNTIME_POINTER');
        $done=$directory==='commands'?'acks':'supervisor-responses';
        m4h_private_meta(M4H_CONTROL.'/'.$done.'/'.$previous['step_nonce'].'.json');
        m4h_assert(m4h_private_meta($active)===$old&&unlink($active),'M4H_REPLACE_ONLY_OWN_ACTIVE_POINTER');}
    m4h_publish($active,m4h_header($payload,'MS4_MERCHANT_HTTP_ACTIVE')+['payload_meta'=>$meta]);
}

/** Whitelist matches the real configuration and Think Env PHP_ lookup. Never expose values. */
function m4h_child_environment(): array
{
    $config=audit_config();
    m4h_assert(($config['database']['username']??'')==='audit_app'&&($config['run_id']??'')===getenv('AUDIT_RUN_ID')
        &&is_string($config['signing_key']??null)&&strlen($config['signing_key'])>=32
        &&is_string($config['auth']['stamp_key']??null)&&strlen($config['auth']['stamp_key'])>=32
        &&!hash_equals($config['signing_key'],$config['auth']['stamp_key'])
        &&($config['auth']['session_epoch']??'')==='audit-http-'.getenv('AUDIT_RUN_ID')
        &&is_string($config['auth']['issuer']??null),'M4H_REAL_PRIVATE_APP_CONFIGURATION');
    return ['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','PHP_APP_DEBUG'=>'false',
        'PHP_APP_APP_KEY'=>$config['signing_key'],'PHP_DATABASE_HOSTNAME'=>'audit-mysql','PHP_DATABASE_HOSTPORT'=>'3306',
        'PHP_DATABASE_DATABASE'=>'audit_auth','PHP_DATABASE_USERNAME'=>'audit_app','PHP_DATABASE_PASSWORD'=>$config['database']['password'],
        'PHP_DATABASE_PREFIX'=>'eb_','PHP_DATABASE_CHARSET'=>'utf8mb4','PHP_DATABASE_DEBUG'=>'false',
        'PHP_CACHE_DRIVER'=>'redis','PHP_REDIS_REDIS_HOSTNAME'=>'audit-redis','PHP_REDIS_PORT'=>'6379',
        'PHP_REDIS_REDIS_PASSWORD'=>$config['redis_password'],'PHP_CACHE_CACHE_PREFIX'=>'c:','PHP_CACHE_CACHE_TAG_PREFIX'=>'CRMEB:',
        'PHP_AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],'PHP_AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],
        'PHP_AUTH_ISSUER'=>$config['auth']['issuer']];
}
function m4h_child_status($process): ?array
{
    if(!is_resource($process))return null;$status=proc_get_status($process);
    return array_intersect_key($status,array_flip(['pid','running','signaled','stopped','exitcode','termsig','stopsig']));
}
/** Exactly one normal TERM; no escalation, proc_terminate, restart or invented exit status. */
function m4h_stop(&$process,?array &$group,array $supervisor,bool &$sent): array
{
    if($group===null&&is_resource($process)&&proc_get_status($process)['running'])
        $group=audit_app_register_group($process,$supervisor);
    $registered=$group;$child=m4h_child_status($process);
    if($group!==null){
        $remaining=audit_app_group_members($group,$supervisor);
        if(!$sent&&$remaining!==[]){
            if(posix_kill(-$group['pgid'],SIGTERM))$sent=true;
            else m4h_assert(audit_app_group_members($group,$supervisor)===[],'M4H_NORMAL_TERM_FAILED');}
        $until=microtime(true)+5.0;
        do{
            $child=m4h_child_status($process);
            while(pcntl_waitpid(-$group['pgid'],$waitStatus,WNOHANG)>0){}
            $remaining=audit_app_group_members($group,$supervisor);
            if($remaining===[]&&!audit_app_listener_open())break;
            usleep(20000);
        }while(microtime(true)<$until);
    }else{$remaining=[];}
    $closed=$remaining===[]&&!audit_app_listener_open();$nativeClose=null;
    if($closed&&is_resource($process)){
        $last=m4h_child_status($process);m4h_assert($last!==null&&!$last['running'],'M4H_DIRECT_CHILD_STILL_RUNNING');
        $child=$last;$nativeClose=proc_close($process);$process=null;$group=null;
    }
    return ['stopped'=>$closed,'group'=>$registered,'remaining_members'=>$remaining,'listener_open'=>audit_app_listener_open(),
        'stop_signal'=>$sent?SIGTERM:null,'native_child'=>$child,'native_proc_close'=>$nativeClose,'normal_only'=>true];
}

function m4h_serve(array $source): int
{
    $runtime=m4h_runtime($source);$supervisor=audit_app_proc_stat(getmypid());m4h_assert($supervisor!==null,'M4H_SUPERVISOR_IDENTITY');
    foreach(['supervisor-requests','supervisor-responses','commands','captures','acks','screenshots'] as $name)
        m4h_directory(M4H_CONTROL.'/'.$name);
    m4h_publish(M4H_CONTROL.'/browser-plan.json',m4h_make_plan($source,$runtime));
    $ready=m4h_header($runtime,'MS4_MERCHANT_HTTP_SOURCE_READY')+[
        'source_binding_sha256'=>$source['source_binding_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
        'public_receipt_sha256'=>$source['public_receipt_sha256'],'supervisor'=>$supervisor,'full_source_verified'=>true];
    m4h_publish(M4H_CONTROL.'/source-ready.json',$ready);
    $process=null;$group=null;$started=false;$sent=false;$generation='';$last='';$terminate=false;
    pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$terminate):void{$terminate=true;});
    while(true){
        if($terminate){$stop=m4h_stop($process,$group,$supervisor,$sent);
            if($stop['stopped']){m4h_publish(M4H_CONTROL.'/signal-stop.json',m4h_header($runtime,'MS4_MERCHANT_HTTP_SIGNAL_STOP')+$stop);return 0;}
            $terminate=false;}
        if(!file_exists(M4H_CONTROL.'/active-supervisor.json')){usleep(50000);continue;}
        // An old completed request may expire; its expiry must never end a live server.
        try{
            $pointer=m4h_private_read(M4H_CONTROL.'/active-supervisor.json',16384);
            if(($pointer['step_nonce']??null)===$last){usleep(50000);continue;}
            $request=m4h_active('active-supervisor.json','supervisor-requests',$source,$runtime);
        }
        catch(Throwable $error){usleep(50000);continue;}
        $last=$request['step_nonce'];
        $response=m4h_header($request,'MS4_MERCHANT_HTTP_STATUS')+['action'=>$request['action'],'status'=>'failed',
            'generation'=>$generation,'supervisor'=>$supervisor,'group'=>$group,'members'=>[],'remaining_members'=>[],
            'listener_open'=>audit_app_listener_open(),'stop_signal'=>$sent?SIGTERM:null,'native_child'=>null,
            'native_proc_close'=>null,'normal_only'=>true,'code'=>'M4H_UNCLASSIFIED'];
        try{
            m4h_keys($request,array_merge(['schema','scope','action'],M4H_IDENTITIES));
            m4h_assert($request['scope']==='MS4_MERCHANT_HTTP_CONTROL'
                &&in_array($request['action'],['start','observe','stop'],true),'M4H_SUPERVISOR_ACTION');
            if($request['action']==='start'){
                m4h_assert(!$started&&$process===null&&$group===null&&!audit_app_listener_open(),'M4H_NO_RESTART');
                audit_guard_assert();$started=true;
                $process=proc_open(['setsid','php','think','run','-H','0.0.0.0','-p','8000'],
                    [['file','/dev/null','r'],['file','/workspace/crmeb/runtime/ms4-http-native.log','a'],
                     ['file','/workspace/crmeb/runtime/ms4-http-native.log','a']],$pipes,'/workspace/crmeb',m4h_child_environment());
                m4h_assert(is_resource($process),'M4H_REAL_HTTP_START');$group=audit_app_register_group($process,$supervisor);$generation=$last;
                $until=microtime(true)+5.0;do{$facts=audit_app_process_observation($group,$supervisor);
                    if($facts['members']!==[]&&$facts['listener_open'])break;usleep(10000);
                }while(microtime(true)<$until);
                m4h_assert($facts['members']!==[]&&$facts['listener_open'],'M4H_REAL_HTTP_LISTENER');
                $response=array_replace($response,$facts,['status'=>'start','generation'=>$generation,'code'=>'M4H_HTTP_STARTED']);
            }elseif($request['action']==='observe'){
                m4h_assert($group!==null&&$generation!=='','M4H_NO_REGISTERED_GENERATION');
                $response=array_replace($response,audit_app_process_observation($group,$supervisor),
                    ['status'=>'observe','generation'=>$generation,'code'=>'M4H_HTTP_OBSERVED']);
            }else{
                m4h_assert($started&&($group!==null||is_resource($process)),'M4H_STOP_REQUIRES_STARTED_GROUP');
                $stop=m4h_stop($process,$group,$supervisor,$sent);
                $response=array_replace($response,$stop,['status'=>$stop['stopped']?'stop':'pending',
                    'generation'=>$generation,'code'=>$stop['stopped']?'M4H_HTTP_STOPPED':'M4H_HTTP_TERM_PENDING']);
                unset($response['stopped']);
            }
        }catch(Throwable $error){
            // Do not kill or restart a partially started group. Preserve it for a normal stop.
            $response['group']=$group;$response['generation']=$generation;$response['status']='failed';
            $response['code']=$error instanceof RuntimeException&&preg_match('/\AM4H_[A-Z0-9_]+\z/D',$error->getMessage())===1
                ?$error->getMessage():'M4H_SUPERVISOR_SOURCE_FAILURE';
        }
        m4h_publish(M4H_CONTROL.'/supervisor-responses/'.$last.'.json',$response);
        // A successful child stop keeps this original supervisor alive for its private getter.
        // It cannot start again; Root later sends normal SIGTERM with no timeout escalation.
    }
}

function m4h_live_supervisor(array $source,array $runtime): array
{
    $ready=m4h_private_read(M4H_CONTROL.'/source-ready.json',16384);
    m4h_assert(($ready['scope']??'')==='MS4_MERCHANT_HTTP_SOURCE_READY'&&($ready['full_source_verified']??null)===true
        &&($ready['source_binding_sha256']??'')===$source['source_binding_sha256']
        &&($ready['native_created_sha256']??'')===$source['native_created_sha256']
        &&($ready['public_receipt_sha256']??'')===$source['public_receipt_sha256'],'M4H_SOURCE_READY_BINDING');
    foreach(['run_id','source_sha','container_id','freeze_sha256','epoch'] as $key)
        m4h_assert(($ready[$key]??null)===($runtime[$key]??null),'M4H_READY_RUNTIME_BINDING');
    $actual=audit_app_proc_stat($ready['supervisor']['pid']??0);
    foreach(['pid','pgid','sid','starttime'] as $key)
        m4h_assert($actual!==null&&$actual[$key]===($ready['supervisor'][$key]??null),'M4H_ACTUAL_LIVE_SUPERVISOR');
    return $ready;
}
function m4h_query(array $query): void
{
    m4h_assert(array_diff(array_keys($query),['page','limit','order_id','paid','is_cancel'])===[],'M4H_QUERY_KEYS');
    foreach($query as $key=>$value){
        m4h_assert(is_int($value)||is_string($value),'M4H_QUERY_SCALAR');
        m4h_assert(match($key){'page'=>in_array((string)$value,['1','2'],true),'limit'=>(string)$value==='20',
            'paid','is_cancel'=>in_array((string)$value,['0','1'],true),
            'order_id'=>is_string($value)&&$value!==''&&strlen($value)<=32&&!str_contains($value,"\0"),default=>false},'M4H_NORMAL_QUERY');
    }
}
function m4h_capture_target(array $capture): void
{
    m4h_keys($capture,['method','path','query']);m4h_assert(is_array($capture['query']),'M4H_CAPTURE_QUERY');m4h_query($capture['query']);
    $path=$capture['path'];$method=$capture['method'];
    m4h_assert(is_string($path)&&!str_contains($path,'?')&&(
        ($method==='POST'&&in_array($path,['/merchantapi/login','/merchantapi/logout'],true))
        ||($method==='GET'&&($path==='/merchantapi/me'||$path==='/merchantapi/orders'
            ||preg_match('~\A/merchantapi/orders/[1-9][0-9]{0,9}\z~D',$path)===1))),'M4H_CAPTURE_REAL_READ_PATH');
    if($path!=='/merchantapi/orders')m4h_assert($capture['query']===[],'M4H_ONLY_ORDER_LIST_QUERY');
}
function m4h_command(array $command,array $source,array $runtime): void
{
    m4h_keys($command,array_merge(['schema','scope','action','args','capture'],M4H_IDENTITIES));m4h_identity($command,$source,$runtime);
    m4h_assert($command['scope']==='MS4_MERCHANT_BROWSER_COMMAND'&&in_array($command['action'],M4H_ACTIONS,true)
        &&is_array($command['args']),'M4H_BROWSER_ACTION');$args=$command['args'];
    m4h_assert(array_diff(array_keys($args),['actor_label','page','direction','order_id','paid_label','cancel_label','heading','viewport','scene'])===[]
        &&in_array($args['actor_label']??null,['owned','stopped','empty'],true),'M4H_NO_CREDENTIAL_COMMAND_ARGS');
    if(isset($args['page']))m4h_assert(is_int($args['page'])&&in_array($args['page'],[1,2],true),'M4H_BROWSER_PAGE');
    if(isset($args['direction']))m4h_assert(in_array($args['direction'],['next','prev'],true),'M4H_NATIVE_PAGE_DIRECTION');
    if(isset($args['order_id']))m4h_assert(is_string($args['order_id'])&&strlen($args['order_id'])<=32
        &&!str_contains($args['order_id'],"\0"),'M4H_NATIVE_ORDER_NO');
    if(isset($args['paid_label']))m4h_assert(in_array($args['paid_label'],['全部','未支付','已支付'],true),'M4H_PAID_FILTER_LABEL');
    if(isset($args['cancel_label']))m4h_assert(in_array($args['cancel_label'],['全部','未取消','已取消'],true),'M4H_CANCEL_FILTER_LABEL');
    if(isset($args['heading']))m4h_assert(in_array($args['heading'],['本店信息','订单列表','订单详情'],true),'M4H_REFRESH_EXISTING_HEADING');
    if(isset($args['viewport']))m4h_viewport($args['viewport']);
    if(isset($args['scene']))m4h_assert(is_string($args['scene'])&&preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D',$args['scene'])===1,'M4H_SCENE_NAME');
    if($command['capture']===null)m4h_assert(in_array($command['action'],['visual','home'],true),'M4H_VISUAL_ONLY_ACTION');
    else{m4h_assert(is_array($command['capture']),'M4H_CAPTURE_OBJECT');m4h_capture_target($command['capture']);}
}
function m4h_viewport(mixed $viewport): void
{
    m4h_assert(is_array($viewport),'M4H_VIEWPORT_OBJECT');m4h_keys($viewport,['width','height']);
    m4h_assert(in_array($viewport['width'],[320,390,1440],true)&&is_int($viewport['height'])
        &&$viewport['height']>=200&&$viewport['height']<=4096,'M4H_REAL_TARGET_VIEWPORT');
}
function m4h_prepare_binding(array $binding,array $source): void
{
    m4h_keys($binding,['container_id','source_sha','freeze_sha256','native_created_sha256','source_binding_sha256',
        'normal_receipt_sha256','public_receipt_sha256','image_url']);
    $base='/run/audit/evidence/';$prepared=m4h_read($base.'ms4-http-preparation-source-binding.json');
    $created=m4h_read($base.'http-prepare-created.json');
    m4h_assert(($prepared['schema']??null)===1&&($prepared['scope']??'')==='MS4_HTTP_PREPARATION_SOURCE'
        &&($prepared['run_id']??'')===$source['run_id']&&($prepared['source_sha']??'')===$source['source_sha']
        &&($prepared['freeze_sha256']??'')===$source['freeze_sha256']
        &&($created['run_id']??'')===$source['run_id']&&($created['source_sha']??'')===$source['source_sha']
        &&($created['id']??null)===$binding['container_id']
        &&$binding['native_created_sha256']===hash_file('sha256',$base.'http-prepare-created.json')
        &&$binding['source_binding_sha256']===hash_file('sha256',$base.'ms4-http-preparation-source-binding.json')
        &&$binding['container_id']===($prepared['container_id']??null)
        &&$binding['native_created_sha256']===($prepared['native_created_sha256']??null)
        &&$binding['normal_receipt_sha256']===($prepared['normal_receipt_sha256']??null)
        &&$binding['public_receipt_sha256']===$source['public_receipt_sha256']
        &&$binding['source_sha']===$source['source_sha']&&$binding['freeze_sha256']===$source['freeze_sha256']
        &&$binding['image_url']==='/__ms4_fixture__/product.png','M4H_REAL_BOOTSTRAP_BINDING');
}
/** Saved PDO oracle from the native preparation, not the production projection. */
function m4h_oracle(array $source): array
{
    $oracle=m4h_private_read(M4H_CONTROL.'/merchant-oracle.json');
    m4h_assert(($oracle['schema']??null)===1&&($oracle['scope']??'')==='NORMAL_MERCHANT_BROWSER_SAVED_ORACLE'
        &&($oracle['run_id']??'')===$source['run_id']&&($oracle['source_sha']??'')===$source['source_sha'],'M4H_PRIVATE_SAVED_ORACLE');
    m4h_prepare_binding($oracle['binding'],$source);return $oracle;
}
function m4h_same(mixed $left,mixed $right): bool
{
    if(!is_array($left)||!is_array($right))return $left===$right;
    if(array_is_list($left)!==array_is_list($right)||count($left)!==count($right))return false;
    if(!array_is_list($left)){ksort($left,SORT_STRING);ksort($right,SORT_STRING);}
    if(array_keys($left)!==array_keys($right))return false;
    foreach($left as $key=>$value)if(!m4h_same($value,$right[$key]))return false;return true;
}

/** Resolve the only dynamic plan values from actual saved rows, never guessed order IDs. */
function m4h_plan_rules(array $source): array
{
    $oracle=m4h_oracle($source);m4h_keys($oracle['actors'],['owned','stopped','empty']);
    m4h_assert(count($oracle['actors']['owned']['orders'])===22&&count($oracle['actors']['stopped']['orders'])===1
        &&$oracle['actors']['empty']['orders']===[]&&count($oracle['new_orders'])===20,'M4H_NATIVE_PLAN_ACTOR_COUNTS');
    $owned=$oracle['actors']['owned']['orders'][0];$stopped=$oracle['actors']['stopped']['orders'][0];
    $native=array_values(array_filter($oracle['new_orders'],static fn(array $row):bool=>
        $row['id']===$owned['list']['id']&&$row['order_id']===$owned['list']['order_id']));
    m4h_assert(count($native)===1&&is_int($owned['list']['id'])&&$owned['list']['id']>0
        &&$owned['detail']['id']===$owned['list']['id']&&$owned['detail']['order_id']===$owned['list']['order_id'],
        'M4H_NATIVE_DEFAULT_NEW_ORDER');
    $duplicates=false;$zero=false;
    foreach($owned['detail']['custom_form'] as $group){$seen=[];
        foreach($group['fields'] as $field){
            if(in_array($field['label'],['text','number'],true)){
                if(isset($seen[$field['title']])&&$seen[$field['title']]!==$field['label'])$duplicates=true;
                $seen[$field['title']]=$field['label'];
                if($field['label']==='number'&&$field['value']==='0')$zero=true;
            }
        }
    }
    m4h_assert($duplicates&&$zero,'M4H_NATIVE_DUPLICATE_TEXT_NUMBER_ZERO_FORM');
    $values=['$N'=>$owned['list']['order_id'],'$P'=>(string)$owned['list']['id'],
        '$S'=>$stopped['list']['order_id'],'$Q'=>(string)$stopped['list']['id']];
    $resolve=static function(mixed $value)use(&$resolve,$values):mixed{
        if(is_string($value))return strtr($value,$values);
        if(is_array($value))foreach($value as &$item)$item=$resolve($item);return $value;
    };
    return $resolve(M4H_PLAN_RULES);
}
function m4h_make_plan(array $source,array $runtime): array
{
    $steps=[];$nonces=[$runtime['step_nonce']=>true];
    foreach(m4h_plan_rules($source) as $rule){
        do{$nonce=bin2hex(random_bytes(24));}while(isset($nonces[$nonce]));$nonces[$nonce]=true;
        $frame=array_replace($runtime,['step_nonce'=>$nonce]);
        $command=m4h_header($frame,'MS4_MERCHANT_BROWSER_COMMAND')+['action'=>$rule['action'],
            'args'=>['actor_label'=>$rule['actor_label']]+$rule['args'],'capture'=>$rule['capture']];
        m4h_command($command,$source,$runtime);$steps[]=['key'=>$rule['key'],'command'=>$command];
    }
    m4h_assert(count($steps)===34,'M4H_COMPLETE_ORDINARY_PLAN');
    return m4h_header($runtime,'MS4_MERCHANT_BROWSER_PLAN')+['version'=>1,'steps'=>$steps,'manual_review_required'=>9];
}
function m4h_plan(array $source,array $runtime): array
{
    $plan=m4h_private_read(M4H_CONTROL.'/browser-plan.json');
    m4h_keys($plan,array_merge(['schema','scope','version','steps','manual_review_required'],M4H_IDENTITIES));
    m4h_identity($plan,$source,$runtime);m4h_assert($plan['scope']==='MS4_MERCHANT_BROWSER_PLAN'
        &&$plan['version']===1&&$plan['manual_review_required']===9&&is_array($plan['steps'])
        &&array_is_list($plan['steps'])&&count($plan['steps'])===34,'M4H_FIXED_PLAN_CONTRACT');
    foreach(M4H_IDENTITIES as $key)m4h_assert($plan[$key]===$runtime[$key],'M4H_PLAN_HEADER_SAME_RUNTIME');
    $rules=m4h_plan_rules($source);$nonces=[];
    foreach($plan['steps'] as $index=>$step){m4h_keys($step,['key','command']);$command=$step['command'];
        m4h_command($command,$source,$runtime);$nonce=$command['step_nonce'];
        m4h_assert(!isset($nonces[$nonce])&&$nonce!==$runtime['step_nonce']&&$step['key']===$rules[$index]['key']
            &&$command['action']===$rules[$index]['action']
            &&m4h_same($command['args'],['actor_label'=>$rules[$index]['actor_label']]+$rules[$index]['args'])
            &&m4h_same($command['capture'],$rules[$index]['capture']),'M4H_SOURCE_BOUND_PLAN_STEP');$nonces[$nonce]=true;
        foreach(M4H_IDENTITIES as $key)if($key!=='step_nonce')
            m4h_assert($command[$key]===$runtime[$key],'M4H_PLAN_SAME_RUNTIME');
    }
    return $plan;
}
function m4h_plan_step(array $plan,string $nonce): array
{
    foreach($plan['steps'] as $index=>$step)if($step['command']['step_nonce']===$nonce)return ['index'=>$index]+$step;
    throw new RuntimeException('M4H_COMMAND_MUST_BELONG_TO_PLAN');
}
function m4h_receipt(string $directory,array $command,array $source,array $runtime): array
{
    $value=m4h_private_read(M4H_CONTROL.'/'.$directory.'/'.$command['step_nonce'].'.json');m4h_identity($value,$source,$runtime);
    foreach(M4H_IDENTITIES as $key)m4h_assert($value[$key]===$command[$key],'M4H_RECEIPT_PLANNED_IDENTITIES');return $value;
}
/** No implicit cursor: persisted exact commands and successful actual ACKs determine the prefix. */
function m4h_next_command(array $plan,array $payload,array $source,array $runtime): void
{
    foreach($plan['steps'] as $step){$command=$step['command'];$path=M4H_CONTROL.'/commands/'.$command['step_nonce'].'.json';
        if(!file_exists($path)){
            m4h_assert(m4h_same($payload,$command),'M4H_EXACT_NEXT_PLAN_COMMAND');return;
        }
        m4h_assert(m4h_same(m4h_receipt('commands',$command,$source,$runtime),$command),'M4H_PERSISTED_EXACT_COMMAND');
        m4h_validate_ack(m4h_receipt('acks',$command,$source,$runtime),$command,$plan,$source,$runtime);
    }
    throw new RuntimeException('M4H_NO_COMMAND_AFTER_COMPLETE_PLAN');
}
/** Derive the visible scene from real UI navigation, including visual steps with no response. */
function m4h_scene(array $command,array $plan): array
{
    $scene=['kind'=>'login'];$target=m4h_plan_step($plan,$command['step_nonce']);
    foreach($plan['steps'] as $index=>$step){if($index>$target['index'])break;$current=$step['command'];
        if(in_array($current['action'],['login','home'],true))$scene=['kind'=>'home'];
        elseif($current['action']==='logout')$scene=['kind'=>'login'];
        elseif($current['capture']!==null&&$current['capture']['path']==='/merchantapi/orders')
            $scene=['kind'=>'list','query'=>$current['capture']['query']];
        elseif($current['capture']!==null&&preg_match('~/orders/([1-9][0-9]*)\z~D',$current['capture']['path'],$match)===1)
            $scene=['kind'=>'detail','id'=>(int)$match[1]];
    }
    return $scene;
}
function m4h_selected(array $actor,array $query): array
{
    $selected=[];
    foreach($actor['orders'] as $row){$order=$row['list'];$matches=true;
        foreach(['order_id','paid','is_cancel'] as $key)
            if(array_key_exists($key,$query)&&(string)$order[$key]!==(string)$query[$key])$matches=false;
        if($matches)$selected[]=$order;
    }
    $page=(int)($query['page']??1);return ['list'=>array_slice($selected,($page-1)*20,20),'count'=>count($selected)];
}

function m4h_saved_response(array $command,array $capture,array $source): void
{
    $oracle=m4h_oracle($source);$actor=$oracle['actors'][$command['args']['actor_label']]??null;
    m4h_assert(is_array($actor),'M4H_REAL_ORACLE_ACTOR');$path=$capture['path'];$data=$capture['data'];
    if($path==='/merchantapi/orders'){
        $query=$capture['query'];$orders=array_column($actor['orders'],'list');$selected=[];
        foreach($orders as $order){$matches=true;
            foreach(['order_id','paid','is_cancel'] as $key)if(array_key_exists($key,$query)&&(string)$order[$key]!==(string)$query[$key])$matches=false;
            if($matches)$selected[]=$order;}
        $page=(int)($query['page']??1);$limit=(int)($query['limit']??20);
        m4h_assert(m4h_same($data,['list'=>array_slice($selected,($page-1)*$limit,$limit),'count'=>count($selected)]),'M4H_HTTP_LIST_EXACT_NATIVE_SAVED_ORACLE');
    }elseif(preg_match('~\A/merchantapi/orders/([1-9][0-9]{0,9})\z~D',$path,$match)===1){
        $found=[];foreach($actor['orders'] as $row)if((string)$row['list']['id']===$match[1])$found[]=$row['detail'];
        m4h_assert(count($found)===1&&m4h_same($data,$found[0]),'M4H_HTTP_DETAIL_EXACT_NATIVE_SAVED_ORACLE');
    }elseif($path==='/merchantapi/me'){
        m4h_assert(is_array($data)&&(int)($data['employee']['id']??0)===$actor['employee_id']
            &&(int)($data['merchant']['mer_id']??0)===$actor['mer_id']&&(int)($data['role']['id']??0)===$actor['role_id'],
            'M4H_HTTP_ME_REAL_NATIVE_ACTOR');
    }
}


function m4h_number(mixed $value): bool
{
    return (is_int($value)||is_float($value))&&is_finite((float)$value);
}
function m4h_box(mixed $box): array
{
    m4h_assert(is_array($box),'M4H_REAL_DOM_RECT');m4h_keys($box,['x','y','width','height']);
    foreach($box as $value)m4h_assert(m4h_number($value),'M4H_FINITE_DOM_RECT');
    m4h_assert($box['width']>=0&&$box['height']>=0,'M4H_NONNEGATIVE_DOM_RECT');return $box;
}
function m4h_inside(array $box,array $parent,bool $horizontal=true): void
{
    m4h_assert($box['y']>=$parent['y']-2&&$box['y']+$box['height']<=$parent['y']+$parent['height']+2,
        'M4H_CONTROL_INSIDE_PARENT_HEIGHT');
    if($horizontal)m4h_assert($box['x']>=$parent['x']-2
        &&$box['x']+$box['width']<=$parent['x']+$parent['width']+2,'M4H_CONTROL_INSIDE_PARENT_WIDTH');
}
/** Same ordinary Run timestamps as formatTime(), with the actual browser timezone offset. */
function m4h_time(int $time,array $dom): string
{
    return $time>0?gmdate('Y/n/j H:i:s',$time-$dom['time_zone_offset_minutes']*60):'—';
}
function m4h_status(array $order): string
{
    if($order['is_cancel']===1)return '已取消';if($order['paid']===0)return '待支付';
    if($order['refund_status']!==0)return '退款状态：'.$order['refund_status'];
    return match($order['status']){0=>$order['shipping_type']===2?'待提货':'待发货',
        1=>$order['shipping_type']===2?'已提货':'待收货',2=>'已收货',3=>'已完成',default=>'订单状态：'.$order['status']};
}
function m4h_image(array $image,string $expected,array $runtime): void
{
    m4h_assert(is_string($image['alt']??null)&&is_bool($image['loaded']??null)
        &&($image['src']??null)===$expected&&is_string($image['current_src']??null)
        &&is_int($image['natural_width']??null)&&is_int($image['natural_height']??null)
        &&$image['natural_width']>=0&&$image['natural_height']>=0
        &&in_array($image['source_kind']??null,['empty','same_origin','external'],true),'M4H_ACTUAL_SAVED_IMAGE_SOURCE');
    m4h_box($image['bounds']??null);
    if($expected==='/__ms4_fixture__/product.png')m4h_assert($image['loaded']===true
        &&$image['source_kind']==='same_origin'&&$image['current_src']===$runtime['origin'].$expected
        &&$image['natural_width']===32&&$image['natural_height']===32,'M4H_REAL_SAME_ORIGIN_32PX_IMAGE_LOADED');
    // Empty and external historical snapshots retain their actual observed loading state.
}
function m4h_item_dom(array $actual,array $expected,bool $detail,array $runtime): void
{
    m4h_assert(($actual['product_name']??null)===$expected['product_name']
        &&($actual['sku_name']??null)===$expected['sku_name']
        &&($actual['price_text']??null)===($detail?'':'¥'.$expected['unit_price'].' × '.$expected['quantity'])
        &&is_array($actual['images']??null)&&count($actual['images'])===1,'M4H_RENDERED_SAVED_PRODUCT_SKU_UNIT_QUANTITY');
    m4h_image($actual['images'][0],$expected['image'],$runtime);
}
/** Only rendered structured DOM is used here; no Vue state, catalog lookup or response substitutes. */
function m4h_dom(array $dom,array $command,array $plan,array $source,array $runtime): void
{
    m4h_assert(is_int($dom['width']??null)&&$dom['width']>0&&is_int($dom['height']??null)&&$dom['height']>0
        &&m4h_number($dom['device_pixel_ratio']??null)&&$dom['device_pixel_ratio']>0
        &&is_int($dom['time_zone_offset_minutes']??null)&&abs($dom['time_zone_offset_minutes'])<=1440
        &&m4h_number($dom['scroll_x']??null)&&abs($dom['scroll_x'])<=2
        &&m4h_number($dom['scroll_y']??null)&&$dom['scroll_y']>=0
        &&($dom['origin']??null)===$runtime['origin']&&($dom['path']??null)==='/merchant/'
        &&($dom['password_values_absent']??null)===true&&($dom['credential_query_absent']??null)===true
        &&is_int($dom['scroll_width']??null)&&$dom['scroll_width']<=$dom['width']
        &&is_int($dom['scroll_height']??null)&&is_int($dom['client_height']??null)
        &&$dom['scroll_height']>=$dom['client_height']&&$dom['client_height']>0
        &&is_bool($dom['login_form_visible']??null)&&is_bool($dom['logout_button_visible']??null),'M4H_REAL_DOM_VIEWPORT_SECRET_AND_DOCUMENT_BOUNDS');
    if($command['action']==='visual')m4h_assert($dom['width']===$command['args']['viewport']['width']
        &&$dom['height']===$command['args']['viewport']['height'],'M4H_PLANNED_CSS_VIEWPORT');
    foreach(['headings','row_cells','row_items','fact_entries','fact_grids','saved_groups','empty','images','controls','tables','messages'] as $key)
        m4h_assert(is_array($dom[$key]??null)&&array_is_list($dom[$key]),'M4H_RENDERED_STRUCTURED_COLLECTION');
    foreach($dom['controls'] as $control){
        m4h_assert(in_array($control['tag']??null,['BUTTON','INPUT','DIV'],true)&&is_string($control['label']??null)
            &&is_bool($control['inside_table_scroll']??null),'M4H_REAL_RENDERED_CONTROL');
        $box=m4h_box($control['bounds']??null);$parent=m4h_box($control['parent_content_bounds']??null);
        m4h_box($control['parent_bounds']??null);m4h_inside($box,$parent,!$control['inside_table_scroll']);
        if(!$control['inside_table_scroll'])m4h_assert($box['x']>=-2&&$box['x']+$box['width']<=$dom['width']+2,
            'M4H_CONTROL_FITS_DOCUMENT_WIDTH');
        if($control['content_bounds']!==null){$content=m4h_box($control['content_bounds']);m4h_inside($content,$box);
            if($control['tag']==='BUTTON')m4h_assert(abs(($content['x']+$content['width']/2)-($box['x']+$box['width']/2))<=4
                &&abs(($content['y']+$content['height']/2)-($box['y']+$box['height']/2))<=4,'M4H_BUTTON_LABEL_GROUP_CENTERED');}
    }
    foreach($dom['messages'] as $message)m4h_assert(is_string($message['text']??null)&&is_string($message['cursor']??null)
        &&in_array($message['role']??null,['status','alert'],true)&&($message['tag']??null)!=='BUTTON'
        &&$message['cursor']!=='pointer','M4H_FEEDBACK_NOT_MISLEADING_ACTION');
    foreach($dom['tables'] as $table){$box=m4h_box($table['bounds']??null);
        foreach(['client_width','scroll_width','scroll_left','scroll_top'] as $key)
            m4h_assert(m4h_number($table[$key]??null)&&$table[$key]>=0,'M4H_REAL_TABLE_SCROLL_METRICS');
        m4h_assert(is_string($table['overflow_x']??null)&&$box['x']>=-2&&$box['x']+$box['width']<=$dom['width']+2
            &&$table['scroll_width']>=$table['client_width'],'M4H_TABLE_INSIDE_DOCUMENT');
        if(($table['last_cell_bounds']??null)!==null)m4h_box($table['last_cell_bounds']);
        if($table['scroll_width']>$table['client_width']+1)m4h_assert(in_array($table['overflow_x'],['auto','scroll'],true),
            'M4H_OVERFLOWING_TABLE_HAS_REAL_SCROLL_CONTAINER');
    }
    $scene=m4h_scene($command,$plan);$kind=$scene['kind'];
    if($kind==='login'){
        m4h_assert($dom['login_form_visible']===true&&$dom['logout_button_visible']===false
            &&$dom['row_cells']===[]&&$dom['fact_entries']===[]&&$dom['saved_groups']===[],'M4H_REAL_NORMAL_LOGOUT_LOGIN_PAGE');return;
    }
    m4h_assert($dom['login_form_visible']===false&&$dom['logout_button_visible']===true,'M4H_REAL_AUTHENTICATED_LAYOUT');
    if($kind==='home'){
        m4h_assert($dom['headings']===['本店信息']&&$dom['row_cells']===[]&&$dom['fact_entries']===[]
            &&$dom['saved_groups']===[],'M4H_NORMAL_HOME_RETURN');return;
    }
    $oracle=m4h_oracle($source);$actor=$oracle['actors'][$command['args']['actor_label']];
    m4h_assert(count($dom['tables'])===1,'M4H_ONE_ACTUAL_ORDER_TABLE');
    if($kind==='list'){
        $data=m4h_selected($actor,$scene['query']);$rows=$data['list'];
        m4h_assert($dom['headings']===['订单列表']&&count($dom['row_cells'])===count($rows)
            &&count($dom['row_items'])===count($rows)&&$dom['fact_entries']===[]&&$dom['saved_groups']===[],
            'M4H_RENDERED_LIST_NATIVE_ROW_COUNT');
        foreach($rows as $index=>$row){$cells=$dom['row_cells'][$index];$items=$dom['row_items'][$index];
            m4h_assert(is_array($cells)&&count($cells)===8&&is_array($items)&&count($items)===count($row['items']),
                'M4H_RENDERED_LIST_CELL_ITEM_SHAPE');
            $first=preg_split('/\R/u',$cells[0]);
            m4h_assert($first[0]===$row['order_id']&&count($first)>=2
                &&trim($first[count($first)-1])===m4h_time($row['add_time'],$dom),
                'M4H_RENDERED_COMPLETE_ORDER_NUMBER_AND_TIME');
            $status=array_values(array_filter(preg_split('/\R/u',$cells[2]),static fn(string $v):bool=>$v!==''));
            m4h_assert($status===[m4h_status($row),($row['paid']===1?'已支付':'未支付').' · '.($row['is_cancel']===1?'已取消':'未取消')]
                &&$cells[3]===(string)$row['total_num']&&$cells[4]===$row['total_price']
                &&$cells[5]===$row['pay_price']&&$cells[6]===$row['pay_postage']&&$cells[7]==='查看详情',
                'M4H_RENDERED_LIST_SAVED_STATUS_QUANTITY_DECIMALS');
            foreach($row['items'] as $itemIndex=>$item)m4h_item_dom($items[$itemIndex],$item,false,$runtime);
        }
        m4h_assert($dom['empty']===($rows===[]?['暂无符合条件的订单']:[]),'M4H_REAL_FILTER_EMPTY_TEXT');
        $page=(int)($scene['query']['page']??1);
        m4h_assert(m4h_same($dom['pagination'],['page'=>$page,
            'prev_disabled'=>$page===1,'next_disabled'=>$page*20>=$data['count']]),'M4H_REAL_PAGER_ACTIVE_AND_DISABLED');return;
    }
    m4h_assert($kind==='detail'&&$dom['headings']===['订单详情']&&$dom['pagination']===null,'M4H_REAL_DETAIL_SCENE');
    $found=array_values(array_filter($actor['orders'],static fn(array $row):bool=>$row['detail']['id']===$scene['id']));
    m4h_assert(count($found)===1,'M4H_ONE_NATIVE_DETAIL_ORACLE');$order=$found[0]['detail'];
    $facts=[['订单号',$order['order_id']],['下单时间',m4h_time($order['add_time'],$dom)],['订单状态',m4h_status($order)],
        ['支付状态',$order['paid']===1?'已支付':'未支付'],['取消状态',$order['is_cancel']===1?'已取消':'未取消'],
        ['退款状态码',(string)$order['refund_status']],['配送方式',$order['shipping_type']===1?'快递配送':'到店自提'],
        ['商品数量',(string)$order['total_num']],['商品金额（元）',$order['total_price']],
        [$order['paid']===1?'实付金额（元）':'应付金额（元）',$order['pay_price']],['邮费（元）',$order['pay_postage']],
        ['收货人',$order['recipient']['real_name']],['联系电话',$order['recipient']['user_phone']],['收货地址',$order['recipient']['user_address']]];
    m4h_assert(count($dom['fact_entries'])===count($facts)&&count($dom['fact_grids'])===2,'M4H_DETAIL_ALL_RENDERED_TERMS');
    foreach($dom['fact_grids'] as $grid){m4h_assert(is_string($grid),'M4H_REAL_FACT_GRID_STYLE');
        $columns=preg_split('/\s+/',trim($grid));
        m4h_assert(count($columns)===($dom['width']<=760?1:2),'M4H_RESPONSIVE_FACT_GRID_COLUMNS');
        foreach($columns as $column)m4h_assert(preg_match('/\A[0-9]+(?:\.[0-9]+)?px\z/D',$column)===1,'M4H_RESOLVED_FACT_GRID_WIDTH');}
    foreach($facts as $index=>[$term,$value]){$actual=$dom['fact_entries'][$index];
        m4h_assert(($actual['term']??null)===$term&&is_string($actual['value']??null)
            &&$actual['value']===$value,'M4H_DETAIL_SAVED_TERM_VALUE');
        $box=m4h_box($actual['bounds']??null);$valueBox=m4h_box($actual['value_bounds']??null);
        m4h_assert($box['x']>=-2&&$box['x']+$box['width']<=$dom['width']+2
            &&is_int($actual['value_client_width']??null)&&is_int($actual['value_scroll_width']??null)
            &&$actual['value_scroll_width']<=$actual['value_client_width']+1,'M4H_DETAIL_SAVED_VALUE_WRAPS_WITHIN_WIDTH');
        m4h_inside($valueBox,$box);
    }
    m4h_assert(count($dom['row_cells'])===count($order['items'])&&count($dom['row_items'])===count($order['items']),
        'M4H_DETAIL_ALL_SAVED_ITEMS');
    foreach($order['items'] as $index=>$item){$cells=$dom['row_cells'][$index];$items=$dom['row_items'][$index];
        m4h_assert(is_array($cells)&&count($cells)===5&&is_array($items)&&count($items)===1
            &&$cells[1]===(string)$item['quantity']&&$cells[2]===$item['unit_price']
            &&$cells[3]===$item['line_price']&&$cells[4]===$item['postage_price'],'M4H_DETAIL_SAVED_ITEM_WHOLE_ROW_MONEY');
        m4h_item_dom($items[0],$item,true,$runtime);
    }
    m4h_assert($dom['mark']===($order['mark']===''?'未填写':$order['mark'])
        &&count($dom['saved_groups'])===count($order['custom_form']),'M4H_DETAIL_SAVED_MARK_AND_GROUP_COUNT');
    foreach($order['custom_form'] as $index=>$group){$actual=$dom['saved_groups'][$index];$groupBox=m4h_box($actual['bounds']??null);
        m4h_assert($groupBox['x']>=-2&&$groupBox['x']+$groupBox['width']<=$dom['width']+2,'M4H_SAVED_GROUP_INSIDE_DOCUMENT');
        m4h_assert(($actual['product_name']??null)===$group['product_name']&&is_array($actual['fields']??null)
            &&count($actual['fields'])===count($group['fields']),'M4H_DETAIL_SAVED_PRODUCT_GROUP_IDENTITY');
        foreach($group['fields'] as $fieldIndex=>$field){$entry=$actual['fields'][$fieldIndex];
            m4h_inside(m4h_box($entry['bounds']??null),$groupBox);
            m4h_assert(($entry['title']??null)===($field['field_index']+1).'. '.$field['title']&&is_string($entry['text']??null)
                &&is_array($entry['images']??null)&&is_int($entry['value_client_width']??null)
                &&is_int($entry['value_scroll_width']??null)&&$entry['value_scroll_width']<=$entry['value_client_width']+1,
                'M4H_DETAIL_FIELD_INDEX_DUPLICATE_TITLE_AND_WRAP');
            if($field['label']==='img'){
                m4h_assert(count($entry['images'])===count($field['value'])
                    &&trim($entry['text'])===($field['value']===[]?'未填写':''),'M4H_SAVED_FORM_IMAGE_LIST');
                foreach($field['value'] as $imageIndex=>$image)m4h_image($entry['images'][$imageIndex],$image,$runtime);
            }else m4h_assert($entry['images']===[]&&$entry['text']===($field['value']===''?'未填写':$field['value']),
                'M4H_DETAIL_EXACT_SAVED_FIELD_VALUE_INCLUDING_ZERO');
        }
    }
}

function m4h_table_position(mixed $position): array
{
    m4h_assert(is_array($position),'M4H_REAL_KEYBOARD_TABLE_OBSERVATION');
    m4h_keys($position,['scroll_left','client_width','scroll_width','bounds','last_cell_bounds']);
    foreach(['scroll_left','client_width','scroll_width'] as $key)
        m4h_assert(m4h_number($position[$key])&&$position[$key]>=0,'M4H_FINITE_KEYBOARD_SCROLL_METRICS');
    m4h_assert($position['scroll_width']>=$position['client_width']
        &&$position['scroll_left']<=$position['scroll_width']-$position['client_width']+2,'M4H_ACTUAL_SCROLL_OFFSET_RANGE');
    m4h_box($position['bounds']);if($position['last_cell_bounds']!==null)m4h_box($position['last_cell_bounds']);return $position;
}
function m4h_close_box(array $left,array $right): void
{
    foreach(['x','y','width','height'] as $key)m4h_assert(abs($left[$key]-$right[$key])<=2,'M4H_SAME_MEASURED_RESTORED_RECT');
}
function m4h_keyboard_reach(array $proof,array $dom): void
{
    m4h_keys($proof,['method','required','verified','right_keypresses','left_keypresses','initial','reached','restored']);
    m4h_assert($proof['method']==='documented_locator_keyboard'&&is_bool($proof['required'])&&$proof['verified']===true,
        'M4H_REAL_DOCUMENTED_KEYBOARD_REACH');
    foreach(['right_keypresses','left_keypresses'] as $key)m4h_assert(is_int($proof[$key])&&$proof[$key]>=0&&$proof[$key]<=64,
        'M4H_BOUNDED_ACTUAL_KEYPRESSES');
    $initial=m4h_table_position($proof['initial']);$reached=m4h_table_position($proof['reached']);$restored=m4h_table_position($proof['restored']);
    m4h_assert(count($dom['tables'])===1,'M4H_ONE_KEYBOARD_ORDER_TABLE');$table=$dom['tables'][0];
    $required=count($dom['row_cells'])>0&&$initial['scroll_width']>$initial['client_width'];
    m4h_assert($proof['required']===$required,'M4H_REACH_REQUIRED_FROM_ACTUAL_ROWS_AND_OVERFLOW');
    foreach([$reached,$restored] as $position)m4h_assert($position['client_width']===$initial['client_width']
        &&$position['scroll_width']===$initial['scroll_width'],'M4H_SAME_TABLE_DURING_ORDINARY_KEYBOARD_INPUT');
    if(!$required)m4h_assert($proof['right_keypresses']===0&&$proof['left_keypresses']===0
        &&m4h_same($initial,$reached)&&m4h_same($initial,$restored),'M4H_MEASURED_NOT_REQUIRED_NO_FAKE_KEYPRESSES');
    else{
        $max=$initial['scroll_width']-$initial['client_width'];
        m4h_assert($initial['client_width']>0&&$initial['scroll_left']<=2&&$reached['scroll_left']>=$max-2
            &&$restored['scroll_left']<=2&&is_array($reached['last_cell_bounds']),'M4H_REAL_RIGHT_EDGE_AND_RESTORED_LEFT');
        $cell=$reached['last_cell_bounds'];$wrapper=$reached['bounds'];
        m4h_assert($cell['x']>=$wrapper['x']-2&&$cell['x']+$cell['width']<=$wrapper['x']+$wrapper['width']+2,
            'M4H_ACTUAL_FINAL_COLUMN_INSIDE_SCROLL_WRAPPER');
        if($initial['scroll_left']<$max-2)m4h_assert($proof['right_keypresses']>0,'M4H_FORWARD_ACTUAL_KEYPRESS_REQUIRED');
        if($reached['scroll_left']>2)m4h_assert($proof['left_keypresses']>0,'M4H_RESTORE_ACTUAL_KEYPRESS_REQUIRED');
    }
    foreach(['client_width','scroll_width'] as $key)m4h_assert($table[$key]===$restored[$key],'M4H_SCREENSHOT_SAME_MEASURED_TABLE');
    m4h_assert(abs($table['scroll_left']-$restored['scroll_left'])<=2,'M4H_SCREENSHOT_ACTUALLY_RESTORED');
    m4h_close_box($table['bounds'],$restored['bounds']);
    if($restored['last_cell_bounds']===null)m4h_assert($table['last_cell_bounds']===null,'M4H_EMPTY_TABLE_HAS_NO_LAST_CELL');
    else m4h_close_box(m4h_box($table['last_cell_bounds']??null),$restored['last_cell_bounds']);
}

function m4h_validate_ack(array $payload,array $command,array $plan,array $source,array $runtime): void
{
    m4h_keys($payload,array_merge(['schema','action','completed','dom_checks'],M4H_IDENTITIES));m4h_identity($payload,$source,$runtime);
    foreach(M4H_IDENTITIES as $key)m4h_assert($payload[$key]===$command[$key],'M4H_ACK_PLANNED_IDENTITIES');
    m4h_assert($payload['action']===$command['action']&&$payload['completed']===true&&is_array($payload['dom_checks']),
        'M4H_ACTUAL_BROWSER_ACTION_ACK');$checks=$payload['dom_checks'];
    $flag=match($command['action']){'login'=>'normal_credential_login','logout'=>'normal_logout',
        'visual'=>'screenshot_saved',default=>'ordinary_UI_action_completed'};
    m4h_keys($checks,$command['action']==='visual'?[$flag,'dom','horizontal_reach']:[$flag,'dom']);m4h_assert($checks[$flag]===true&&is_array($checks['dom']),'M4H_ACTUAL_ACTION_DOM_PROOF');
    if($command['capture']!==null)m4h_receipt('captures',$command,$source,$runtime);
    if($command['action']==='visual'){$shot=m4h_receipt('screenshots',$command,$source,$runtime);
        m4h_assert(m4h_same($shot['dom'],$checks['dom']),'M4H_VISUAL_ACK_SAME_SCREENSHOT_DOM');}
    m4h_dom($checks['dom'],$command,$plan,$source,$runtime);
    if($command['action']==='visual'){m4h_assert(is_array($checks['horizontal_reach']),'M4H_ACTUAL_KEYBOARD_PROOF_OBJECT');
        m4h_keyboard_reach($checks['horizontal_reach'],$checks['dom']);}
}

function m4h_validate_capture(array $payload,array $command,array $source): void
{
    m4h_keys($payload,array_merge(['schema','method','path','query','request_id','mime_type','http_status','business_status',
        'time_started','time_finished','body_base64','body_bytes','body_sha256','data'],M4H_IDENTITIES));
    m4h_assert($command['capture']!==null,'M4H_EXPECTED_PRIVATE_CAPTURE');
    foreach(['method','path'] as $key)m4h_assert($payload[$key]===$command['capture'][$key],'M4H_OBSERVED_CAPTURE_TARGET');
    m4h_assert(is_array($payload['query']),'M4H_ACTUAL_WIRE_QUERY');m4h_query($payload['query']);
    foreach($payload['query'] as $value)m4h_assert(is_string($value),'M4H_WIRE_QUERY_STRING');
    m4h_assert(m4h_same($payload['query'],array_map(static fn(mixed $value):string=>(string)$value,$command['capture']['query'])),
        'M4H_OBSERVED_EXACT_QUERY');
    m4h_assert(is_string($payload['request_id'])&&$payload['request_id']!==''&&strlen($payload['request_id'])<=200
        &&is_string($payload['mime_type'])&&str_contains($payload['mime_type'],'json')
        &&$payload['http_status']===200&&$payload['business_status']===200
        &&is_int($payload['time_started'])&&is_int($payload['time_finished'])
        &&$payload['time_started']>=$command['not_before']&&$payload['time_finished']>=$payload['time_started']
        &&$payload['time_finished']<=$command['deadline'],'M4H_GENUINE_FINITE_SUCCESS_CAPTURE');
    m4h_assert(is_string($payload['body_base64']),'M4H_ACTUAL_RESPONSE_BYTES');$raw=base64_decode($payload['body_base64'],true);
    m4h_assert(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=1048576&&strlen($raw)===$payload['body_bytes']
        &&hash('sha256',$raw)===$payload['body_sha256'],'M4H_CAPTURE_FULL_BYTES_HASH');
    $envelope=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
    m4h_assert(is_array($envelope)&&($envelope['status']??null)===200&&is_string($envelope['msg']??null)
        &&array_key_exists('data',$envelope)&&m4h_same($envelope['data'],$payload['data']),'M4H_CAPTURE_ACTUAL_BODY_DATA');
    m4h_saved_response($command,$payload,$source);
}

function m4h_validate_screenshot(array $payload,array $command,array $plan,array $source,array $runtime): void
{
    $nonce=$command['step_nonce'];
    m4h_keys($payload,array_merge(['schema','scene','path','bytes','sha256','viewport','pixels','dom'],M4H_IDENTITIES));
    m4h_assert($command['action']==='visual'
        &&($command['args']['scene']??null)===$payload['scene'],'M4H_CURRENT_SCREENSHOT_SCENE');m4h_viewport($payload['viewport']);
    m4h_assert(m4h_same($payload['viewport'],$command['args']['viewport']??null)&&is_array($payload['pixels']),'M4H_ACTUAL_SCREENSHOT_VIEWPORT');
    m4h_keys($payload['pixels'],['width','height']);$relative='evidence/browser-screenshots/'.$nonce.'-'.$payload['scene'].'.jpg';
    m4h_assert($payload['path']===$relative&&is_int($payload['bytes'])&&$payload['bytes']>0&&$payload['bytes']<=16777216
        &&preg_match('/\A[a-f0-9]{64}\z/D',(string)$payload['sha256'])===1,'M4H_CLOSED_SCREENSHOT_PATH');
    $path='/run/audit/'.$relative;$stat=lstat($path);$image=getimagesize($path);
    m4h_assert(is_array($stat)&&is_file($path)&&!is_link($path)&&realpath($path)===$path&&filesize($path)===$payload['bytes']
        &&hash_file('sha256',$path)===$payload['sha256']&&is_array($image)&&($image['mime']??'')==='image/jpeg'
        &&$image[0]===$payload['pixels']['width']&&$image[1]===$payload['pixels']['height'],'M4H_EXISTING_SCREENSHOT_REAL_BYTES_AND_PIXELS');
    m4h_assert(is_array($payload['dom'])&&($payload['dom']['width']??null)===$payload['viewport']['width']
        &&($payload['dom']['height']??null)===$payload['viewport']['height']&&($payload['dom']['path']??'')==='/merchant/'
        &&in_array($payload['dom']['headings']??null,[['订单列表'],['订单详情']],true)
        &&is_int($payload['dom']['scroll_width']??null)&&$payload['dom']['scroll_width']<=$payload['viewport']['width']
        &&($payload['dom']['password_values_absent']??null)===true&&($payload['dom']['credential_query_absent']??null)===true,
        'M4H_SCREENSHOT_RENDERED_DOM_AND_SECRET_EXCLUSION');
    m4h_dom($payload['dom'],$command,$plan,$source,$runtime);
}


function m4h_validate_review(array $payload,array $command,array $plan,array $source,array $runtime): void
{
    m4h_keys($payload,array_merge(['schema','scope','scene','sha256','reviewed','issues','review_method'],M4H_IDENTITIES));
    m4h_identity($payload,$source,$runtime);
    foreach(M4H_IDENTITIES as $key)m4h_assert($payload[$key]===$command[$key],'M4H_REVIEW_ORIGINAL_VISUAL_IDENTITIES');
    m4h_assert($command['action']==='visual'&&$payload['scope']==='MS4_MERCHANT_BROWSER_VISUAL_REVIEW'
        &&$payload['scene']===$command['args']['scene']&&$payload['reviewed']===true&&$payload['issues']===[]
        &&$payload['review_method']==='actual_image_inspection','M4H_REAL_IMAGE_MANUAL_REVIEW_CONTRACT');
    $shot=m4h_receipt('screenshots',$command,$source,$runtime);
    m4h_validate_screenshot($shot,$command,$plan,$source,$runtime);
    m4h_assert($payload['sha256']===$shot['sha256'],'M4H_REVIEW_EXACT_EXISTING_JPEG_HASH');
}
function m4h_closed_files(string $directory,array $names): void
{
    m4h_directory(M4H_CONTROL.'/'.$directory);$actual=[];
    foreach(new DirectoryIterator(M4H_CONTROL.'/'.$directory) as $file)if(!$file->isDot()){
        m4h_assert($file->isFile()&&!$file->isLink(),'M4H_RECEIPT_DIRECTORY_ONLY_REGULAR_FILES');
        $actual[]=$file->getFilename();
    }
    sort($actual,SORT_STRING);sort($names,SORT_STRING);m4h_assert($actual===$names,'M4H_EXACT_COMPLETE_PLAN_RECEIPT_CLOSED_SET');
}
/** Revalidate evidence semantics, not merely the number of commands that were executed. */
function m4h_complete_plan(array $source,array $runtime): array
{
    $plan=m4h_plan($source,$runtime);$names=['commands'=>[],'captures'=>[],'acks'=>[],'screenshots'=>[]];$shots=[];
    foreach($plan['steps'] as $step){$command=$step['command'];$nonce=$command['step_nonce'];
        m4h_assert(m4h_same(m4h_receipt('commands',$command,$source,$runtime),$command),'M4H_COMPLETE_EXACT_PLAN_COMMAND');
        $names['commands'][]=$nonce.'.json';$names['acks'][]=$nonce.'.json';
        if($command['capture']!==null){$capture=m4h_receipt('captures',$command,$source,$runtime);
            m4h_validate_capture($capture,$command,$source);$names['captures'][]=$nonce.'.json';}
        m4h_validate_ack(m4h_receipt('acks',$command,$source,$runtime),$command,$plan,$source,$runtime);
        if($command['action']==='visual'){
            $shot=m4h_receipt('screenshots',$command,$source,$runtime);m4h_validate_screenshot($shot,$command,$plan,$source,$runtime);
            $review=m4h_private_read(M4H_CONTROL.'/screenshots/'.$nonce.'-review.json');
            m4h_validate_review($review,$command,$plan,$source,$runtime);
            $names['screenshots'][]=$nonce.'.json';$names['screenshots'][]=$nonce.'-review.json';
            $shots[]=array_intersect_key($shot,array_flip(['scene','path','bytes','sha256','viewport','pixels']));
        }
    }
    m4h_assert(count($names['commands'])===34&&count($names['captures'])===25&&count($names['acks'])===34
        &&count($shots)===9&&count($names['screenshots'])===18,'M4H_COMPLETE_REQUIRED_COVERAGE_COUNTS');
    foreach($names as $directory=>$files)m4h_closed_files($directory,$files);
    return ['plan'=>$plan,'screenshots'=>$shots];
}
/** Every visual state needs its actual keyboard/readback evidence, including measured not-required states. */
function m4h_horizontal_reach(array $plan,array $source,array $runtime): void
{
    $count=0;
    foreach($plan['steps'] as $step)if($step['command']['action']==='visual'){
        $ack=m4h_receipt('acks',$step['command'],$source,$runtime);
        m4h_keyboard_reach($ack['dom_checks']['horizontal_reach'],$ack['dom_checks']['dom']);$count++;
    }
    m4h_assert($count===9,'M4H_ALL_NINE_VISUAL_KEYBOARD_OBSERVATIONS');
}

function m4h_write(array $frame,array $source,array $runtime): void
{
    m4h_keys($frame,['kind','payload']);$kind=$frame['kind'];$payload=$frame['payload'];
    m4h_assert(is_string($kind)&&is_array($payload),'M4H_PRIVATE_WRITE_FRAME');m4h_identity($payload,$source,$runtime);
    $nonce=$payload['step_nonce'];
    if($kind==='supervisor'){
        m4h_keys($payload,array_merge(['schema','scope','action'],M4H_IDENTITIES));
        m4h_assert($payload['scope']==='MS4_MERCHANT_HTTP_CONTROL'&&in_array($payload['action'],['start','observe','stop'],true),'M4H_PRIVATE_SUPERVISOR_REQUEST');
        m4h_activate('active-supervisor.json','supervisor-requests',$payload);return;
    }
    if($kind==='command'){m4h_command($payload,$source,$runtime);
        m4h_next_command(m4h_plan($source,$runtime),$payload,$source,$runtime);m4h_activate('active-command.json','commands',$payload);return;}
    if($kind==='review'){
        $plan=m4h_plan($source,$runtime);$step=m4h_plan_step($plan,$nonce);$command=$step['command'];
        m4h_assert(m4h_same(m4h_receipt('commands',$command,$source,$runtime),$command),'M4H_REVIEW_ACTUAL_COMMAND');
        m4h_validate_review($payload,$command,$plan,$source,$runtime);
        m4h_publish(M4H_CONTROL.'/screenshots/'.$nonce.'-review.json',$payload);return;
    }
    if($kind==='finish'){
        m4h_keys($payload,array_merge(['schema','scope','action'],M4H_IDENTITIES));
        m4h_assert($payload['scope']==='MS4_MERCHANT_BROWSER_FINISH'&&$payload['action']==='close-owned-tabs','M4H_FINITE_BROWSER_FINISH');
        $coverage=m4h_complete_plan($source,$runtime);m4h_horizontal_reach($coverage['plan'],$source,$runtime);
        m4h_publish(M4H_CONTROL.'/finish-browser.json',$payload);return;
    }
    if($kind==='closed'){
        m4h_keys($payload,array_merge(['schema','scope','closed_owned_tab_ids','viewport_reset'],M4H_IDENTITIES));
        $finish=m4h_private_read(M4H_CONTROL.'/finish-browser.json',16384);
        foreach(M4H_IDENTITIES as $key)m4h_assert($payload[$key]===$finish[$key],'M4H_CLOSED_PLANNED_FINISH_IDENTITIES');
        m4h_assert($payload['scope']==='MS4_MERCHANT_BROWSER_CLOSED'&&$payload['step_nonce']===$finish['step_nonce']
            &&is_array($payload['closed_owned_tab_ids'])&&count($payload['closed_owned_tab_ids'])===1
            &&is_string($payload['closed_owned_tab_ids'][0])&&$payload['closed_owned_tab_ids'][0]!==''
            &&$payload['viewport_reset']===true,'M4H_ONLY_OWNED_BROWSER_TAB_CLOSED');
        m4h_publish(M4H_CONTROL.'/browser-closed.json',$payload);return;
    }
    m4h_assert(in_array($kind,['capture','ack','screenshot'],true),'M4H_FINITE_PRIVATE_WRITE_KIND');
    $command=m4h_active('active-command.json','commands',$source,$runtime);m4h_command($command,$source,$runtime);
    foreach(M4H_IDENTITIES as $key)m4h_assert($payload[$key]===$command[$key],'M4H_WRITE_CURRENT_COMMAND');
    if($kind==='capture'){
        m4h_validate_capture($payload,$command,$source);m4h_publish(M4H_CONTROL.'/captures/'.$nonce.'.json',$payload);return;
    }
    if($kind==='ack'){
        m4h_keys($payload,array_merge(['schema','action','completed','dom_checks'],M4H_IDENTITIES));
        m4h_validate_ack($payload,$command,m4h_plan($source,$runtime),$source,$runtime);
        m4h_publish(M4H_CONTROL.'/acks/'.$nonce.'.json',$payload);return;
    }
    m4h_validate_screenshot($payload,$command,m4h_plan($source,$runtime),$source,$runtime);
    m4h_publish(M4H_CONTROL.'/screenshots/'.$nonce.'.json',$payload);
}


function m4h_browser_closed(array $source,array $runtime): array
{
    $finish=m4h_private_read(M4H_CONTROL.'/finish-browser.json',16384);m4h_identity($finish,$source,$runtime);
    m4h_keys($finish,array_merge(['schema','scope','action'],M4H_IDENTITIES));
    m4h_assert($finish['scope']==='MS4_MERCHANT_BROWSER_FINISH'&&$finish['action']==='close-owned-tabs','M4H_FINITE_FINISH_BEFORE_AUDIT');
    $closed=m4h_private_read(M4H_CONTROL.'/browser-closed.json',16384);m4h_identity($closed,$source,$runtime);
    m4h_keys($closed,array_merge(['schema','scope','closed_owned_tab_ids','viewport_reset'],M4H_IDENTITIES));
    foreach(M4H_IDENTITIES as $key)m4h_assert($closed[$key]===$finish[$key],'M4H_CLOSED_SAME_FINISH_IDENTITIES');
    m4h_assert($closed['scope']==='MS4_MERCHANT_BROWSER_CLOSED'&&$closed['viewport_reset']===true
        &&is_array($closed['closed_owned_tab_ids'])&&array_is_list($closed['closed_owned_tab_ids'])
        &&count($closed['closed_owned_tab_ids'])===1&&is_string($closed['closed_owned_tab_ids'][0])
        &&$closed['closed_owned_tab_ids'][0]!=='','M4H_ONE_OWNED_TAB_CLOSED_AND_VIEWPORT_RESET');return $finish;
}

function m4h_audit(array $source,array $runtime): array
{
    $coverage=m4h_complete_plan($source,$runtime);
    $baseline=m4h_private_read(M4H_CONTROL.'/sql-baseline.json');
    m4h_assert(($baseline['schema']??null)===1&&($baseline['scope']??'')==='NORMAL_MERCHANT_BROWSER_SQL_BASELINE'
        &&($baseline['run_id']??'')===$source['run_id']&&($baseline['source_sha']??'')===$source['source_sha'],'M4H_REAL_SQL_BASELINE');
    m4h_prepare_binding($baseline['binding'],$source);
    m4h_assert(hash('sha256',serialize($baseline['facts']))===$baseline['facts_sha256'],'M4H_BASELINE_FULL_FACT_DIGEST');
    require_once __DIR__.'/lib/ms4-merchant-browser-normal.php';
    $pdo=audit_db();m4h_assert(!$pdo->inTransaction(),'M4H_INDEPENDENT_AUDIT_PDO');$actual=m4mb_sql_facts($pdo);
    m4h_assert($actual===$baseline['facts']&&hash('sha256',serialize($actual))===$baseline['facts_sha256'],'M4H_OBSERVED_23_SQL_TABLES_UNCHANGED');
    $finish=m4h_browser_closed($source,$runtime);
    $full=m4h_source(true);
    foreach(['run_id','source_sha','container_id','freeze_sha256','source_binding_sha256','native_created_sha256','public_receipt_sha256'] as $key)
        m4h_assert($full[$key]===$source[$key],'M4H_FINAL_FULL_SOURCE_UNCHANGED');
    $proof=m4h_header($finish,'MS4_MERCHANT_BROWSER_SQL_AUDIT')+['verified'=>true,'table_count'=>count($actual),
        'observed_sql_facts_unchanged'=>true,'facts_sha256'=>hash('sha256',serialize($actual)),
        'full_source_unchanged'=>true,'scope_limit'=>'ORIGINAL_M4O_FACT_TABLES_ONLY_NOT_ALL_DATABASE_TABLES'];
    m4h_assert(count($actual)===23,'M4H_ORIGINAL_23_TABLE_SCOPE');
    m4h_horizontal_reach($coverage['plan'],$source,$runtime);
    $sqlMeta=m4h_publish(M4H_CONTROL.'/sql-audit.json',$proof);
    $result=m4h_header($finish,'MS4_MERCHANT_BROWSER_HTTP_DOM_AUDIT')+[
        'status'=>'VERIFIED_NORMAL_MERCHANT_ORDER_HTTP_DOM_ONLY','verified'=>true,
        'source_binding_sha256'=>$source['source_binding_sha256'],'native_created_sha256'=>$source['native_created_sha256'],
        'public_receipt_sha256'=>$source['public_receipt_sha256'],'plan_sha256'=>m4h_private_meta(M4H_CONTROL.'/browser-plan.json')['sha256'],
        'sql_audit_sha256'=>$sqlMeta['sha256'],'command_count'=>34,'capture_count'=>25,'screenshot_count'=>9,'manual_review_count'=>9,
        'actor_count'=>3,'owned_count'=>22,'stopped_count'=>1,'empty_count'=>0,'owned_page_rows'=>[20,2],
        'filters_verified'=>true,'saved_amounts_verified'=>true,'saved_form_text_verified'=>true,
        'same_origin_fixture_images_loaded'=>true,'horizontal_table_reach_verified'=>true,
        'return_refresh_navigation_verified'=>true,'normal_logouts'=>3,
        'screenshots'=>$coverage['screenshots'],'observed_sql_table_count'=>23,'observed_sql_facts_unchanged'=>true,
        'full_source_unchanged'=>true,'browser_owned_tabs_closed'=>1,'viewport_reset'=>true,
        'scope_limit'=>'NORMAL_MERCHANT_ORDER_HTTP_DOM_ONLY_OBSERVED_23_SQL_TABLES','stopped_samples'=>'NOT RUN',
        'external_payment_shipping_aftersale'=>'NOT RUN'];
    m4h_publish(M4H_CONTROL.'/browser-audit.json',$result);return $result;
}
function m4h_ingress(string $mode,array $source): void
{
    $runtime=m4h_runtime($source);m4h_live_supervisor($source,$runtime);
    if($mode==='runtime'){$value=$runtime;}
    elseif($mode==='plan'){$value=m4h_plan($source,$runtime);}
    elseif($mode==='command'){$value=m4h_active('active-command.json','commands',$source,$runtime);m4h_command($value,$source,$runtime);}
    elseif($mode==='supervisor'){
        $request=m4h_active('active-supervisor.json','supervisor-requests',$source,$runtime);
        m4h_keys($request,array_merge(['schema','scope','action'],M4H_IDENTITIES));
        m4h_assert($request['scope']==='MS4_MERCHANT_HTTP_CONTROL'
            &&in_array($request['action'],['start','observe','stop'],true),'M4H_SUPERVISOR_GETTER_REQUEST');
        $path=M4H_CONTROL.'/supervisor-responses/'.$request['step_nonce'].'.json';$response=null;
        if(file_exists($path)){
            $response=m4h_private_read($path);m4h_identity($response,$source,$runtime);
            foreach(M4H_IDENTITIES as $key)m4h_assert($response[$key]===$request[$key],'M4H_SUPERVISOR_REPLY_SAME_NONCE');
            m4h_assert(($response['scope']??'')==='MS4_MERCHANT_HTTP_STATUS'&&($response['action']??null)===$request['action']
                &&in_array($response['status']??null,['start','observe','stop','pending','failed'],true),'M4H_ACTUAL_SUPERVISOR_REPLY');
        }
        $value=m4h_header($request,'MS4_MERCHANT_HTTP_SUPERVISOR_REPLY')+
            ['state'=>$response===null?'not_ready':'response','response'=>$response];
    }
    elseif($mode==='finish'){$value=m4h_private_read(M4H_CONTROL.'/finish-browser.json',16384);m4h_identity($value,$source,$runtime);}
    elseif($mode==='audit'){$value=m4h_audit($source,$runtime);}
    else{
        m4h_assert($mode==='write','M4H_INGRESS_MODE');$raw=stream_get_contents(STDIN,2097153);
        m4h_assert(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=2097152,'M4H_BOUNDED_STDIN_PRIVATE_FRAME');
        $frame=json_decode($raw,true,128,JSON_THROW_ON_ERROR);m4h_assert(is_array($frame),'M4H_PRIVATE_STDIN_OBJECT');
        m4h_write($frame,$source,$runtime);fwrite(STDOUT,"MS4_PRIVATE_WRITE_OK\n");return;
    }
    // Private pipe only: the caller must never render actors, tokens, responses or full auth fingerprints.
    fwrite(STDOUT,m4h_json($value));
}
function m4h_main(): int
{
    try{
        set_error_handler(static function(int $severity):bool{
            // Preserve the harness's deliberate @ reads of processes that have normally exited.
            if((error_reporting()&$severity)===0)return false;
            throw new RuntimeException('M4H_PHP_RUNTIME_WARNING');
        });
        $args=$_SERVER['argv']??[];
        m4h_assert(($args[1]??'')==='serve'&&count($args)===2||($args[1]??'')==='ingress'&&count($args)===3
            &&in_array($args[2],['runtime','plan','command','supervisor','write','finish','audit'],true),'M4H_CLOSED_NATIVE_ARGV');
        $source=m4h_source($args[1]==='serve');
        if($args[1]==='serve')return m4h_serve($source);
        m4h_ingress($args[2],$source);return 0;
    }catch(Throwable $error){
        $code=$error instanceof RuntimeException&&preg_match('/\AM4H_[A-Z0-9_]+\z/D',$error->getMessage())===1
            ?$error->getMessage():'M4H_ENTRY_SOURCE_FAILURE';
        fwrite(STDERR,'MS4_HTTP_ENTRY_FAILED '.$code."\n");return 1;
    }
}
if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(m4h_main());
