<?php
declare(strict_types=1);

/** Fresh synthetic component feedback only; no business flow or cache repair. */
require_once __DIR__.'/ms4-aftersale-review-normal.php';

const M4LC_SQL=['bytes'=>10237372,'sha256'=>'7af188f42c622db523d8000c0cf50f95fa48df2fe41123969ca2dbb65ffb5eb2'];
const M4LC_COLUMNS=[
 'eb_lang_type'=>['id','language_name','file_name','status','is_default','is_del'],
 'eb_lang_country'=>['id','type_id','code','name'],
 'eb_lang_code'=>['id','type_id','code','remarks','lang_explain','is_admin'],
];
const M4LC_KEYS=['range_name','sys_lang_source_map','type_id_zh-CN','lang_type_data','lang_zh_CN'];
const M4LC_EVIDENCE='/run/audit/evidence/';

final class M4LCBlocked extends RuntimeException {}
function m4lc_require(bool $ok,string $code):void
{
 if(!$ok)throw new M4LCBlocked($code);
}
function m4lc_pin(string $raw):array
{return ['bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];}
function m4lc_file_pin(string $path):array
{
 m4lc_require(is_file($path)&&!is_link($path)&&realpath($path)===$path,'SOURCE_REGULAR_CANONICAL');
 $raw=file_get_contents($path);m4lc_require(is_string($raw),'SOURCE_COMPLETE_READ');return m4lc_pin($raw);
}
function m4lc_publish(string $phase,array $value):void
{
 $name=$phase==='before-install'?'ms4-language-cache-before-install.json':'ms4-language-cache-install-and-warm.json';
 $path=M4LC_EVIDENCE.$name;
 m4lc_require(is_dir(M4LC_EVIDENCE)&&!is_link(rtrim(M4LC_EVIDENCE,'/'))&&!file_exists($path)&&!is_link($path),'NEW_OWN_PUBLIC_RECEIPT');
 $raw=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
 $stream=fopen($path,'xb');m4lc_require(is_resource($stream),'EXCLUSIVE_PUBLIC_RECEIPT');
 try{m4lc_require(fwrite($stream,$raw)===strlen($raw)&&fflush($stream),'FULL_PUBLIC_RECEIPT_WRITE');}
 finally{fclose($stream);}
 m4lc_require(chmod($path,0600)&&file_get_contents($path)===$raw,'PUBLIC_RECEIPT_READBACK');
}
function m4lc_identity():array
{
 m4lc_require(PHP_SAPI==='cli'&&PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300&&PHP_INT_SIZE===8,'REAL_PHP82_CLI64');
 m4lc_require(audit_run_dir()==='/run/audit'&&audit_repo_dir()==='/workspace','FIXED_NEW_COMPONENT_MOUNTS');
 $manifest=audit_manifest();
 m4lc_require(($manifest['guard_mode']??null)==='read-only','ORDINARY_READ_ONLY_GUARD_REQUIRED');
 audit_guard_assert();
 $base=getenv('AUDIT_SOURCE_SHA');$run=getenv('AUDIT_RUN_ID');$host=gethostname();
 m4lc_require(is_string($base)&&preg_match('/\A[a-f0-9]{40}\z/D',$base)===1
  &&is_string($host)&&preg_match('/\A[a-f0-9]{12}\z/D',$host)===1,'EXACT_BASE_SOURCE_AND_NATIVE_HOSTNAME');
 if(isset($manifest['commit_sha']))m4lc_require($manifest['commit_sha']===$base,'SAME_BASE_COMMIT_ONLY');
 $sources=['component_source'=>m4lc_file_pin(__FILE__),'language_source'=>m4lc_file_pin(__DIR__.'/ms4-aftersale-review-normal.php')];
 foreach($sources as$name=>$pin)m4lc_require(($manifest[$name]??null)===$pin,'GENUINE_COMPONENT_AND_SHARED_LEAF_SOURCE');
 m4lc_require(function_exists('m4ar_language_warm')&&function_exists('m4ar_language_decode')&&function_exists('m4tr_cache_wire'),'REAL_SHARED_FUNCTIONS_PRESENT');
 return ['run_id'=>$run,'base_source_sha'=>$base,'source_identity'=>'BASE_COMMIT_PLUS_EXACT_UNCOMMITTED_SOURCE_FINGERPRINTS',
  'sources'=>$sources,'php_pid'=>getmypid(),'container_hostname'=>$host];
}
function m4lc_db():PDO
{
 $db=audit_db();m4lc_require($db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false)&&$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false),'NATIVE_PDO_TYPES');
 m4lc_require($db->query('SELECT CURRENT_USER()')->fetchColumn()==='audit_app@%'&&!$db->inTransaction(),'OWN_APP_DML_ACCOUNT');
 $db->exec('SET SESSION information_schema_stats_expiry=0');
 $names=$db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
 m4lc_require($names===['audit_sentinel','eb_lang_code','eb_lang_country','eb_lang_type'],'EXACT_THREE_LANGUAGE_TABLES_AND_SENTINEL');
 foreach(M4LC_COLUMNS as$table=>$columns){
  $statement=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
  $statement->execute([$table]);m4lc_require($statement->fetchAll(PDO::FETCH_COLUMN)===$columns,'ORIGINAL_LANGUAGE_COLUMN_ORDER');
  $statement=$db->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
  $statement->execute([$table]);$row=$statement->fetch();
  m4lc_require(is_array($row)&&$row['ENGINE']==='InnoDB'&&str_starts_with($row['TABLE_COLLATION'],'utf8mb4_'),'ORIGINAL_LANGUAGE_ENGINE_CHARSET');
  $pk=$db->query('SHOW KEYS FROM `'.$table.'` WHERE Key_name=\'PRIMARY\'')->fetchAll();
  m4lc_require(count($pk)===1&&$pk[0]['Column_name']==='id'&&(int)$pk[0]['Seq_in_index']===1,'ORIGINAL_LANGUAGE_PRIMARY_KEY');
 }
 return $db;
}
function m4lc_rows(PDO $db):array
{
 $rows=[];foreach(M4LC_COLUMNS as$table=>$columns)$rows[$table]=$db->query('SELECT * FROM `'.$table.'` ORDER BY `id`')->fetchAll();
 return $rows;
}
function m4lc_empty(PDO $db):void
{
 foreach(m4lc_rows($db)as$rows)m4lc_require($rows===[],'ONLY_EMPTY_LANGUAGE_FIXTURE_BEFORE_INSTALL');
 foreach(['eb_lang_type'=>11,'eb_lang_country'=>351,'eb_lang_code'=>25001]as$table=>$counter){
  $query=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
  $query->execute([$table]);m4lc_require((string)$query->fetchColumn()===(string)$counter,'ORIGINAL_SOURCE_LANGUAGE_COUNTER');
 }
}
function m4lc_redis_observe(Redis $redis):array
{
 $observed=[];$present=[];
 foreach(M4LC_KEYS as$name){
  $key='c:'.$name;$type=$redis->type($key);m4lc_require(in_array($type,[Redis::REDIS_NOT_FOUND,Redis::REDIS_STRING],true),'LANGUAGE_CACHE_REAL_NATIVE_TYPE');
  $raw=$redis->get($key);m4lc_require($type===Redis::REDIS_NOT_FOUND?$raw===false:is_string($raw),'LANGUAGE_CACHE_NATIVE_GET_SHAPE');
  $expiry=$redis->rawCommand('PEXPIRETIME',$key);m4lc_require(is_int($expiry)&&($type===Redis::REDIS_NOT_FOUND?$expiry===-2:($expiry===-1||$expiry>0)),'LANGUAGE_CACHE_ABSOLUTE_EXPIRY');
  m4lc_require($redis->type($key.'_lock')===Redis::REDIS_NOT_FOUND,'NO_LEFTOVER_LANGUAGE_LOCK');
  $observed[$name]=['type'=>$type===Redis::REDIS_NOT_FOUND?'none':'string','raw_type'=>gettype($raw),
   'raw_bytes'=>is_string($raw)?strlen($raw):null,'raw_sha256'=>is_string($raw)?hash('sha256',$raw):null,'expires_ms'=>$expiry];
  if($type!==Redis::REDIS_NOT_FOUND)$present[]=$key;
 }
 $tag='c:CRMEB:'.md5('crmeb');$tagType=$redis->type($tag);
 m4lc_require(in_array($tagType,[Redis::REDIS_NOT_FOUND,Redis::REDIS_SET],true),'REAL_LANGUAGE_TAG_TYPE');
 $members=$tagType===Redis::REDIS_SET?$redis->sMembers($tag):[];sort($members,SORT_STRING);sort($present,SORT_STRING);
 m4lc_require($members===$present&&($tagType===Redis::REDIS_NOT_FOUND||$redis->rawCommand('PEXPIRETIME',$tag)===-1),'EXACT_REAL_LANGUAGE_TAG_MEMBERS');
 m4lc_require($redis->dbSize()===count($present)+($tagType===Redis::REDIS_SET?1:0)+1,'ONLY_KNOWN_LANGUAGE_KEYS_AND_OWN_SENTINEL');
 return ['keys'=>$observed,'tag_member_hashes'=>array_map(static fn(string $key):string=>hash('sha256',$key),$members),
  'tag_permanent'=>$tagType===Redis::REDIS_SET,'database_keys'=>$redis->dbSize()];
}
function m4lc_app(string $phase):\think\App
{
 m4lc_require(empty($GLOBALS['m4lc_app_created'])&&!file_exists('/workspace/crmeb/.env')&&!is_link('/workspace/crmeb/.env'),'ONE_REAL_APP_NO_UNBOUND_ENV');
 $GLOBALS['m4lc_app_created']=true;require_once '/workspace/crmeb/vendor/autoload.php';
 require_once '/workspace/crmeb/vendor/topthink/framework/src/helper.php';
 $private=audit_config();
 m4lc_require(is_string($private['signing_key']??null)&&strlen($private['signing_key'])>=32&&is_array($private['auth']??null)
  &&is_string($private['auth']['stamp_key']??null)&&strlen($private['auth']['stamp_key'])>=32
  &&!hash_equals($private['signing_key'],$private['auth']['stamp_key'])&&is_string($private['auth']['session_epoch']??null)
  &&$private['auth']['session_epoch']!==''&&is_string($private['auth']['issuer']??null)&&$private['auth']['issuer']!=='','FRESH_SYNTHETIC_PRIVATE_APP_CONFIGURATION');
 $app=new \think\App('/workspace/crmeb/');
 $runtime='/tmp/ms4-language-cache-'.$phase.'/';m4lc_require(!file_exists($runtime)&&mkdir($runtime,0700),'NEW_PHASE_RUNTIME');$app->setRuntimePath($runtime);
 $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$private['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
  'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$private['database']['password'],
  'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'CACHE_DRIVER'=>'redis',
  'REDIS_REDIS_HOSTNAME'=>'audit-redis','REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$private['redis_password'],'REDIS_SELECT'=>0,
  'CACHE_CACHE_PREFIX'=>'c:','CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$private['auth']['session_epoch'],
  'AUTH_STAMP_KEY'=>$private['auth']['stamp_key'],'AUTH_ISSUER'=>$private['auth']['issuer']]);
 $app->initialize();m4lc_require($app->initialized()&&app()===$app,'REAL_APPLICATION_INITIALIZED');
 $database=$app->config->get('database');m4lc_require(is_array($database),'REAL_DATABASE_CONFIGURATION');
 $database['connections']['mysql']['params']=[PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
 $database['connections']['mysql']['break_reconnect']=false;$app->config->set($database,'database');
 m4lc_require($app->config->get('cache.default')==='redis'&&$app->config->get('cache.stores.redis.type')==='redis'
  &&$app->config->get('cache.stores.redis.host')==='audit-redis'&&(string)$app->config->get('cache.stores.redis.port')==='6379'
  &&$app->config->get('cache.stores.redis.select')===0&&$app->config->get('cache.stores.redis.prefix')==='c:'
  &&$app->config->get('cache.stores.redis.tag_prefix')==='CRMEB:'&&$app->config->get('cache.stores.redis.serialize')===[]
  &&$app->config->get('cache.stores.redis.password')===$private['redis_password'],'REAL_OWN_REDIS_CACHE_CONFIGURATION');
 m4lc_require($app->request->header('cb-lang')===null&&$app->request->header('accept-language')===null,'REAL_REQUEST_WITHOUT_LANGUAGE_OVERRIDE');
 return $app;
}
function m4lc_install(PDO $db):array
{
 m4lc_require(empty($GLOBALS['m4lc_app_created'])&&!$db->inTransaction(),'INSTALL_BEFORE_SECOND_APP');$sql=null;
 foreach(['/workspace','/source']as$root){
  $path=$root.'/crmeb/public/install/crmeb.sql';m4lc_require(m4lc_file_pin($path)===M4LC_SQL,'EXACT_ORIGINAL_INSTALL_SQL');
  $raw=file_get_contents($path);m4lc_require(is_string($raw),'COMPLETE_ORIGINAL_SQL_READ');
  if($sql===null)$sql=$raw;else m4lc_require($sql===$raw,'BOTH_READ_ONLY_SOURCE_ROOTS_IDENTICAL');
 }
 $literal=m4ar_language_decode($sql);unset($sql,$raw);
 m4lc_require(array_keys($literal)===array_keys(M4LC_COLUMNS)&&array_map('count',$literal)===['eb_lang_type'=>1,'eb_lang_country'=>1,'eb_lang_code'=>2515],'COMPLETE_CANONICAL_SOURCE_LANGUAGE_ROWS');
 m4lc_require($db->beginTransaction(),'ONE_REAL_LANGUAGE_DML_TRANSACTION');
 try{
  foreach($literal as$table=>$rows){
   $columns=M4LC_COLUMNS[$table];$sql='INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
   $statement=$db->prepare($sql);m4lc_require($statement instanceof PDOStatement,'REAL_PARAMETERIZED_SOURCE_INSERT');
   foreach($rows as$row){
    m4lc_require(array_keys($row)===$columns,'ALL_ORIGINAL_SOURCE_COLUMNS');
    foreach(array_values($row)as$i=>$value){m4lc_require(is_int($value)||is_string($value),'ORIGINAL_SOURCE_SCALAR_TYPE');
     m4lc_require($statement->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR),'EXACT_SOURCE_PARAMETER_TYPE');}
    m4lc_require($statement->execute()&&$statement->rowCount()===1,'ONE_EXPLICIT_SOURCE_PK_INSERT');
   }
  }
  m4lc_require($db->commit()&&!$db->inTransaction(),'REAL_COMMITTED_LANGUAGE_INSTALL');
 }catch(Throwable $error){if($db->inTransaction())try{$db->rollBack();}catch(Throwable $ignored){}throw $error;}
 m4lc_require(m4lc_rows($db)===$literal,'COMPLETE_NATIVE_LANGUAGE_SOURCE_RESELECT');return $literal;
}
function m4lc_run(string $phase):array
{
 $identity=m4lc_identity();$db=m4lc_db();m4lc_empty($db);$redis=audit_redis();
 m4lc_require($redis->getOption(Redis::OPT_SERIALIZER)===Redis::SERIALIZER_NONE,'INDEPENDENT_RAW_REDIS_OBSERVER');
 if($phase==='before-install'){
  m4lc_require($redis->dbSize()===1,'FRESH_REDIS_ONLY_OWN_SENTINEL');$app=m4lc_app($phase);getLang('操作成功');
  m4lc_require($redis->get('c:sys_lang_source_map')===serialize([]),'ACTUAL_EMPTY_LANGUAGE_GETLANG_PRODUCER');
  m4lc_empty($db);$observation=m4lc_redis_observe($redis);
  return [0,['schema'=>1,'scope'=>'MS4_LANGUAGE_CACHE_COMPONENT_ONLY','phase'=>$phase,'status'=>'VERIFIED_REAL_EMPTY_LANGUAGE_GETLANG',
   'identity'=>$identity,'language_counts'=>['eb_lang_type'=>0,'eb_lang_country'=>0,'eb_lang_code'=>0],
   'native_observation'=>$observation,'real_getLang_called'=>true,'cache_values_not_written_by_component'=>true,'full_acceptance'=>'INCOMPLETE']];
 }
 $proofPath=M4LC_EVIDENCE.'ms4-language-cache-before-install.json';
 m4lc_require(is_file($proofPath)&&!is_link($proofPath)&&realpath($proofPath)===$proofPath,'REAL_FIRST_PHASE_PUBLIC_PROOF');
 $proof=json_decode(file_get_contents($proofPath),true,512,JSON_THROW_ON_ERROR);
 m4lc_require(is_array($proof)&&($proof['status']??null)==='VERIFIED_REAL_EMPTY_LANGUAGE_GETLANG'
  &&($proof['phase']??null)==='before-install'&&($proof['identity']['run_id']??null)===$identity['run_id']
  &&($proof['identity']['base_source_sha']??null)===$identity['base_source_sha']&&($proof['identity']['sources']??null)===$identity['sources']
  &&($proof['identity']['container_hostname']??null)!==$identity['container_hostname'],'SAME_FIXTURE_DISTINCT_NATIVE_APP_PROCESS');
 $raw=$redis->get('c:sys_lang_source_map');m4lc_require(is_string($raw)&&$raw===serialize([])
  &&m4lc_pin($raw)===['bytes'=>$proof['native_observation']['keys']['sys_lang_source_map']['raw_bytes'],
   'sha256'=>$proof['native_observation']['keys']['sys_lang_source_map']['raw_sha256']],'FIRST_REAL_EMPTY_LANGUAGE_CACHE_PRESERVED');
 $literal=m4lc_install($db);$before=m4lc_redis_observe($redis);$app=m4lc_app($phase);$lang=m4ar_language_warm($app);
 m4lc_require(array_keys($lang)===M4LC_KEYS&&$lang['range_name']==='zh-CN'
  &&(is_int($lang['type_id_zh-CN'])||is_string($lang['type_id_zh-CN']))&&(string)$lang['type_id_zh-CN']==='1'
  &&$lang['lang_type_data']===[1=>'zh-CN']&&is_array($lang['sys_lang_source_map'])&&$lang['sys_lang_source_map']!==[],'REAL_INSTALLED_LANGUAGE_LEAF_VALUES');
 $nativeMap=$db->query('SELECT `remarks`,`code` FROM `eb_lang_code` WHERE `type_id`=1 ORDER BY `id`')->fetchAll(PDO::FETCH_KEY_PAIR);
 m4lc_require($lang['sys_lang_source_map']===$nativeMap&&m4lc_rows($db)===$literal,'REAL_LEAF_MAP_MATCHES_COMPLETE_NATIVE_DB');
 $after=m4lc_redis_observe($redis);$raw=$redis->get('c:sys_lang_source_map');$wire=m4tr_cache_wire($nativeMap);
 $context=['logical_name'=>'sys_lang_source_map','normal_ordinal'=>37,'expected_null'=>false,
  'expected_wire_bytes'=>strlen($wire),'expected_wire_sha256'=>hash('sha256',$wire),'raw_type'=>gettype($raw),
  'raw_bytes'=>is_string($raw)?strlen($raw):null,'raw_sha256'=>is_string($raw)?hash('sha256',$raw):null];
 $result=['schema'=>1,'scope'=>'MS4_LANGUAGE_CACHE_COMPONENT_ONLY','phase'=>$phase,'identity'=>$identity,
  'source_language_counts'=>array_map('count',$literal),'native_before_warm'=>$before,'native_after_warm'=>$after,
  'wire_context'=>$context,'same_real_language_leaf'=>true,'cache_values_not_written_by_component'=>true,
  'original_full40_TTL_and_business_acceptance'=>'NOT_ASSERTED_BY_THIS_COMPONENT','full_acceptance'=>'INCOMPLETE'];
 if(!is_string($raw)||$raw!==$wire){
  m4lc_require(is_string($raw)&&$raw===serialize([]),'TARGET_MISMATCH_MUST_BE_ORIGINAL_REAL_EMPTY_MAP');
  $result['status']='TARGET_LANGUAGE_CACHE_MISMATCH';$result['specific_target_mismatch']=true;return [1,$result];
 }
 foreach($lang as$name=>$value)m4lc_require($redis->get('c:'.$name)===m4tr_cache_wire($value),'OTHER_LANGUAGE_CACHE_WIRE_MISMATCH');
 $result['status']='VERIFIED_SOURCE_LANGUAGE_CACHE_COMPONENT';$result['specific_target_mismatch']=false;return [0,$result];
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
 if(($argv??[])===[$_SERVER['SCRIPT_FILENAME'],'--help']){
  echo "Usage: php help/audit-regressions/isolated/ms4-language-cache-normal.php before-install|install-and-warm\nFresh synthetic isolated component only. Native0 pass,1 exact target wire mismatch,2 setup/unrelated failure.\n";exit(0);
 }
 $phase=$argv[1]??'';
 if(!in_array($phase,['before-install','install-and-warm'],true)||count($argv)!==2){fwrite(STDERR,"BLOCKED exact component phase required\n");exit(2);}
 $GLOBALS['m4ar_assertions']=0;
 try{
  [$exit,$result]=m4lc_run($phase);m4lc_publish($phase,$result);echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($exit);
 }catch(Throwable $error){
  $result=['schema'=>1,'scope'=>'MS4_LANGUAGE_CACHE_COMPONENT_ONLY','phase'=>$phase,'status'=>'BLOCKED_COMPONENT_PRECONDITION_OR_UNRELATED_FAILURE',
   'exception_class'=>get_class($error),'assertion'=>$error instanceof M4LCBlocked?$error->getMessage():null,
   'specific_target_mismatch'=>false,'full_acceptance'=>'INCOMPLETE'];
  try{m4lc_publish($phase,$result);}catch(Throwable $publicationError){}
  echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit(2);
 }
}
