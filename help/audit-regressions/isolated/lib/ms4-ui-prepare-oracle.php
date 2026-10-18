<?php
declare(strict_types=1);

/** Own complete prepare-window observer. Defines functions only; no application or file action on import. */
function m4upo_tree(string $root):?array
{
 clearstatcache(true,$root);if(!file_exists($root)){m4up_expect(!is_link($root),'UI_FILE_NO_DANGLING_LINK');return null;}
 m4up_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'UI_FILE_REAL_ROOT');
 $paths=[''=>$root];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
 foreach($it as$f){m4up_expect(!$f->isLink(),'UI_FILE_COMPLETE_NO_SYMLINK');$paths[substr($f->getPathname(),strlen($root)+1)]=$f->getPathname();}
 ksort($paths,SORT_STRING);$out=[];
 foreach($paths as$name=>$path){clearstatcache(true,$path);$st=lstat($path);m4up_expect(is_array($st),'UI_FILE_NATIVE_STAT');
  $row=['kind'=>is_dir($path)?'directory':'file','dev'=>$st['dev'],'ino'=>$st['ino'],'mode'=>$st['mode']&07777,'uid'=>$st['uid'],'gid'=>$st['gid'],'nlink'=>$st['nlink']];
  m4up_expect(($st['mode']&0170000)===($row['kind']==='directory'?0040000:0100000),'UI_FILE_ONLY_REAL_REGULAR_OR_DIRECTORY');
  if($row['kind']==='file'){$row+=['bytes'=>$st['size'],'sha256'=>hash_file('sha256',$path),'mtime'=>$st['mtime'],'ctime'=>$st['ctime']];
   clearstatcache(true,$path);$again=lstat($path);foreach(['dev','ino','mode','uid','gid','nlink','size','mtime','ctime']as$k)
    m4up_expect($again[$k]===$st[$k],'UI_FILE_COMPLETE_NATIVE_HASH_STABLE');}
  $out[$name]=$row;
 }return $out;
}
function m4upo_snapshot(PDO $db):array
{
 $database=m5pcp_database_snapshot($db);$rows=[];
 foreach($database['names']as$name){$rows[$name]=m5pcp_rows($db,'SELECT * FROM '.m5pcp_quote($name));
  m4up_expect(m4fn_projection($rows[$name])===$database['tables'][$name]['projection'],'UI_WINDOW_EVERY_FULL_NATIVE_TABLE_ROW');}
 $redis=audit_redis();$atomic=m5pcp_atomic_redis($redis);$members=[];
 foreach(['crmeb','api']as$tag){$key='c:CRMEB:'.md5($tag);$kind=$redis->rawCommand('EVAL_RO',"return redis.call('TYPE',KEYS[1]).ok",1,$key);
  m4up_expect(in_array($kind,['none','set'],true),'UI_WINDOW_SOURCE_TAG_TYPE');$members[$key]=$kind==='none'?[]:$redis->sMembers($key);
  m4up_expect(is_array($members[$key]),'UI_WINDOW_SOURCE_NATIVE_MEMBERS');sort($members[$key],SORT_STRING);}
 $files=[];foreach(['/run/audit-fixtures',M4AR_PRIVATE,'/workspace/crmeb/public','/workspace/crmeb/runtime',
  '/tmp/ms4-fulfillment-normal-'.getenv('AUDIT_RUN_ID')]as$p)$files[$p]=m4upo_tree($p);
 return ['database'=>$database,'rows'=>$rows,'redis'=>$atomic,'tag_members'=>$members,'files'=>$files];
}
function m4upo_descriptor(array $snapshot):array
{
 return ['sha256'=>hash('sha256',serialize($snapshot)),'database_sha256'=>hash('sha256',serialize([$snapshot['database'],$snapshot['rows']])),
 'redis_sha256'=>hash('sha256',serialize([$snapshot['redis']['keys'],$snapshot['tag_members']])),
 'files_sha256'=>hash('sha256',serialize($snapshot['files'])),'table_count'=>count($snapshot['database']['names'])];
}
function m4upo_one(array $rows,string $key,mixed $id):array
{
 $found=[];foreach($rows as$r)if((string)$r[$key]===(string)$id)$found[]=$r;
 m4up_expect(count($found)===1,'UI_WINDOW_ONE_REAL_NATIVE_ROW');return $found[0];
}
function m4upo_next(array $before,string $table,int $offset=0):int
{return m5pcp_int(bcadd(m5pcp_u64($before['database']['tables'][$table]['metadata']['table']['AUTO_INCREMENT']),(string)$offset,0),1);}
function m4upo_time(mixed $value,array $interval):int
{
 $n=m5pcp_int($value,1,4294967295);m4up_expect($n>=$interval[0]&&$n<=$interval[1],'UI_WINDOW_NATIVE_TIME_BOUND');return $n;
}
function m4upo_row(array $before,array $actual,string $table,array $values):array
{return m4fn_expected_row($before['database']['tables'][$table]['metadata'],$actual,$values);}
function m4upo_menu_rows(array $before,array $after,array $menus):array
{
 $old=$before['rows']['eb_system_menus'];$new=$after['rows']['eb_system_menus'];$anchors=[];
 foreach(['order_root'=>'admin-order','order_page'=>'admin-order-storeOrder-index','order_info_group'=>'order-info','order_info'=>'order-info-6465a369a2c3b']as$name=>$unique){
  $rows=array_values(array_filter($old,static fn(array $r):bool=>$r['unique_auth']===$unique));
  m4up_expect(count($rows)===1,'UI_WINDOW_ORIGINAL_ORDER_ANCHOR');$anchors[$name]=$rows[0];
  m4up_expect(m4upo_one($new,'id',$rows[0]['id'])===$rows[0],'UI_WINDOW_FULL_ORIGINAL_ANCHOR_UNCHANGED');
 }
 $definitions=['order_root'=>['unique_auth'=>'admin-order','auth_type'=>'1','menu_path'=>'/order','api_url'=>'','methods'=>'','pid'=>'0'],
 'order_page'=>['unique_auth'=>'admin-order-storeOrder-index','auth_type'=>'1','menu_path'=>'/order/list','api_url'=>'','methods'=>''],
 'order_info_group'=>['unique_auth'=>'order-info','auth_type'=>'3','menu_path'=>'/','api_url'=>'','methods'=>''],
 'order_info'=>['unique_auth'=>'order-info-6465a369a2c3b','auth_type'=>'2','api_url'=>'order/info/<id>','methods'=>'GET']];
 foreach($definitions as$name=>$wanted){$r=$anchors[$name];m4up_expect(count($r)===22,'UI_WINDOW_FULL_NATIVE_ANCHOR_COLUMN_SET');
  m5pcp_int($r['id'],1,65535);
  foreach($wanted+['is_show'=>'1','is_show_path'=>'1','access'=>'1','is_del'=>'0']as$k=>$v)
   m4up_expect($r[$k]!==null&&(string)$r[$k]===$v,'UI_WINDOW_INDEPENDENT_EXACT_ANCHOR_FIELDS');}
 m4up_expect((string)$anchors['order_page']['pid']===(string)$anchors['order_root']['id']
  &&(string)$anchors['order_info_group']['pid']===(string)$anchors['order_page']['id']
  &&(string)$anchors['order_info']['pid']===(string)$anchors['order_info_group']['id'],'UI_WINDOW_INDEPENDENT_COMPLETE_ORDER_ANCHOR_CHAIN');
 $proof=[];foreach($anchors as$name=>$row)$proof[$name]=['id'=>(int)$row['id'],'sha256'=>hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
 foreach($menus as$result)m4c_entry_same($result['anchors'],$proof);
 $pid=m5pcp_int($anchors['order_page']['id'],1,65535);
 $common=['pid'=>$pid,'icon'=>'','module'=>'','controller'=>'','action'=>'','params'=>'[]','sort'=>1,'is_show'=>1,'is_show_path'=>1,
 'access'=>1,'menu_path'=>'','path'=>'','auth_type'=>2,'header'=>'','is_header'=>0,'is_del'=>0,'mark'=>'marketplace-ms4-order-read-menus-v1'];
 $expected=$old;$ids=[];$spec=[['读取订单列表','order/list','order-list-read'],['读取订单统计','order/chart','order-chart-read']];
 foreach($spec as$i=>[$name,$path,$unique]){
  foreach($old as$r)m4up_expect($r['unique_auth']!==$unique
   &&!(strtolower((string)$r['methods'])==='get'&&strtolower((string)$r['api_url'])===$path),'UI_WINDOW_TWO_ACTUAL_NEW_LEAVES');
  $id=m4upo_next($before,'eb_system_menus',$i);$actual=m4upo_one($new,'id',$id);
  $expected[]=m4upo_row($before,$actual,'eb_system_menus',['id'=>$id]+$common+['menu_name'=>$name,'methods'=>'GET','api_url'=>$path,'unique_auth'=>$unique]);$ids[$unique]=$id;
 }
 m4c_entry_same($menus['apply']['created_menu_ids'],$ids);foreach($menus as$r)if($r['state']==='completed')m4c_entry_same($r['menu_ids'],$ids);
 return $expected;
}
function m4upo_grants(array $after):array
{
 $routes=[['GET','marketplace/aftersales'],['GET','marketplace/aftersales/<id>'],['POST','marketplace/aftersales/<id>/approve'],
 ['POST','marketplace/aftersales/<id>/reject'],['POST','marketplace/aftersales/<id>/return-accept'],['POST','marketplace/aftersale-files/<file_id>/read-ticket'],
 ['GET','marketplace/aftersale-files/<file_id>/content'],['GET','marketplace/reviews'],['GET','marketplace/reviews/<id>'],['PUT','marketplace/reviews/<id>/review'],
 ['GET','order/list'],['GET','order/chart'],['GET','order/info/<id>']];
 $ids=[];$all=$after['rows']['eb_system_menus'];
 foreach($routes as[$method,$path]){$r=array_values(array_filter($all,static fn(array $x):bool=>(int)$x['auth_type']===2
  &&(int)$x['is_del']===0&&strtolower((string)$x['api_url'])===strtolower($path)&&strtolower((string)$x['methods'])===strtolower($method)));
  m4up_expect(count($r)===1,'UI_WINDOW_EXACT_FINAL_THIRTEEN_NATIVE_API_LEAVES');$ids[]=m5pcp_int($r[0]['id'],1,65535);}
 foreach(['admin-order','admin-order-storeOrder-index']as$u){$r=array_values(array_filter($all,static fn(array $x):bool=>$x['unique_auth']===$u
  &&(int)$x['auth_type']===1&&(int)$x['is_del']===0));m4up_expect(count($r)===1,'UI_WINDOW_EXACT_TWO_NATIVE_NAV_GRANTS');
  foreach(['access','is_show','is_show_path']as$k)m4up_expect((int)$r[0][$k]===1,'UI_WINDOW_ACTIVE_NAVIGATION');
  $ids[]=m5pcp_int($r[0]['id'],1,65535);}
 sort($ids,SORT_NUMERIC);m4up_expect(count($ids)===15&&count(array_unique($ids))===15,'UI_WINDOW_FINAL_FIFTEEN_NATIVE_IDS');return $ids;
}
function m4upo_calls(#[\SensitiveParameter] array $credentials):array
{
 $calls=$GLOBALS['m4ar_http_calls']??null;m4up_expect(is_array($calls)&&count($calls)===3,'UI_PREPARE_EXACT_THREE_LOGIN_REQUESTS');
 $spec=[['merchant','/merchantapi/login',['account'=>$credentials['merchant']['account'],'password'=>$credentials['merchant']['password']]],
 ['platform','/adminapi/login',['account'=>$credentials['platform']['account'],'pwd'=>$credentials['platform']['password'],'key'=>'']],
 ['customer','/api/login',['account'=>$credentials['customer']['account'],'password'=>$credentials['customer']['password'],'spread'=>0,'agent_id'=>0]]];$intervals=[];
 foreach($spec as$i=>[$kind,$path,$body]){$call=$calls[$i];$raw=m4cb_json($body);
  m4up_expect($call['method']==='POST'&&$call['path']===$path&&$call['http']===200&&$call['request_bytes']===strlen($raw)
   &&$call['request_sha256']===hash('sha256',$raw)&&$call['bytes']>0&&$call['bytes']<=6291456&&m5pc_hex($call['sha256']),'UI_WINDOW_REAL_LOGIN_REQUEST_BODY_IDENTITY');
  $range=$call['actual_interval'];m4up_expect(is_array($range)&&array_is_list($range)&&count($range)===2
   &&is_int($range[0])&&is_int($range[1])&&$range[0]>0&&$range[1]>=$range[0],'UI_WINDOW_REAL_LOGIN_INTERVAL');$intervals[$kind]=$range;
 }return $intervals;
}
function m4upo_actor_rows(array $before,array $after,array $owner,array $actors,#[\SensitiveParameter] array $c,array $interval):array
{
 $calls=m4upo_calls($c);m4c_entry_keys($c,['customer','merchant','platform']);
 m4up_expect($c['customer']['uid']===$owner['uid']&&$c['customer']['account']===$owner['loaded']['private']['account']
  &&$c['customer']['password']===$owner['loaded']['private']['password']&&strlen($c['customer']['password'])===24
  &&strlen($c['merchant']['password'])===49&&strlen($c['platform']['password'])===31,'UI_WINDOW_ORIGINAL_REAL_CREDENTIALS');
 $ma=$c['merchant']['account'];$pa=$c['platform']['account'];
 m4up_expect(preg_match('/\Am78_([a-f0-9]{14})\z/D',$ma,$m)===1&&$pa==='a78_'.$m[1],'UI_WINDOW_ONE_REAL_RANDOM_ACTOR_SUFFIX');$suffix=$m[1];
 $mer=m5pcp_int($owner['fixture']['merchant']['mer_id'],1,4294967295);
 $roleId=m4upo_next($before,'eb_marketplace_role');$employeeId=m4upo_next($before,'eb_marketplace_employee');
 $setupId=m4upo_next($before,'eb_marketplace_password_setup');$adminRoleId=m4upo_next($before,'eb_system_role');$adminId=m4upo_next($before,'eb_system_admin');
 m4up_expect($actors['merchant']['id']===$employeeId&&$actors['merchant']['role_id']===$roleId&&$actors['merchant']['mer_id']===$mer
  &&$actors['platform']['id']===$adminId&&$actors['platform']['role_id']===$adminRoleId&&$actors['customer']['id']===$owner['uid']
  &&$c['merchant']['id']===$employeeId&&$c['merchant']['mer_id']===$mer&&$c['platform']['id']===$adminId,'UI_WINDOW_FIVE_NATURAL_IDS_AND_THREE_REAL_LOGINS');
 $role=m4upo_one($after['rows']['eb_marketplace_role'],'id',$roleId);
 $created=m4upo_time($role['add_time'],$interval);m4up_expect((int)$role['update_time']===$created,'UI_WINDOW_ROLE_ONE_REAL_INSERT_TIME');
 $permissions=['merchant.profile.read','order.read','order.ship','aftersale.handle','product.read','product.write'];
 $wantedRole=m4upo_row($before,$role,'eb_marketplace_role',['id'=>$roleId,'mer_id'=>$mer,'name'=>'Normal '.$suffix,
  'permissions'=>['json'=>$permissions],'add_time'=>$created,'update_time'=>$created]);
 $employee=m4upo_one($after['rows']['eb_marketplace_employee'],'id',$employeeId);
 m4up_expect(is_string($employee['pwd'])&&password_verify($c['merchant']['password'],$employee['pwd'])
  &&(password_get_info($employee['pwd'])['algoName']??null)==='bcrypt','UI_WINDOW_REAL_SETUP_MERCHANT_PASSWORD_HASH');
 $update=m4upo_time($employee['update_time'],$interval);
 $wantedEmployee=m4upo_row($before,$employee,'eb_marketplace_employee',['id'=>$employeeId,'mer_id'=>$mer,'role_id'=>$roleId,'account'=>$ma,
  'pwd'=>$employee['pwd'],'name'=>'Normal','avatar'=>'','status'=>1,'is_del'=>0,'add_time'=>$created,'update_time'=>$update]);
 $setup=m4upo_one($after['rows']['eb_marketplace_password_setup'],'id',$setupId);
 m4up_expect(is_string($setup['token_hash'])&&strlen($setup['token_hash'])===32,'UI_WINDOW_ACTUAL_RANDOM_SETUP_DIGEST');
 $consumed=m4upo_time($setup['consumed_at'],$interval);
 m4up_expect($created<=$update&&$update<=$calls['merchant'][0]&&$consumed>=$created
  &&$consumed<=$calls['merchant'][0],'UI_WINDOW_REAL_SETUP_BEFORE_FIRST_MERCHANT_LOGIN');
 $wantedSetup=m4upo_row($before,$setup,'eb_marketplace_password_setup',['id'=>$setupId,'mer_id'=>$mer,'employee_id'=>$employeeId,
  'token_hash'=>$setup['token_hash'],'expires_at'=>$created+86400,'consumed_at'=>$consumed,'add_time'=>$created]);
 $grants=m4upo_grants($after);m4up_expect($actors['grants']===$grants,'UI_WINDOW_EXACT_FINAL_ROLE_GRANTS');
 $roleAdmin=m4upo_one($after['rows']['eb_system_role'],'id',$adminRoleId);
 $wantedRoleAdmin=m4upo_row($before,$roleAdmin,'eb_system_role',['id'=>$adminRoleId,'role_name'=>'N78 '.$suffix,'rules'=>implode(',',$grants),'level'=>1,'status'=>1]);
 $admin=m4upo_one($after['rows']['eb_system_admin'],'id',$adminId);
 m4up_expect(is_string($admin['pwd'])&&password_verify($c['platform']['password'],$admin['pwd'])
  &&(password_get_info($admin['pwd'])['algoName']??null)==='bcrypt','UI_WINDOW_REAL_PLATFORM_PASSWORD_HASH');
 $at=m4upo_time($admin['add_time'],$interval);$last=m4upo_time($admin['last_time'],$calls['platform']);
 $loginDefault=$before['database']['tables']['eb_system_admin']['metadata']['columns']['login_count']['COLUMN_DEFAULT'];
 m4up_expect($loginDefault!==null,'UI_WINDOW_REAL_LOGIN_COUNT_DEFAULT');
 $wantedAdmin=m4upo_row($before,$admin,'eb_system_admin',['id'=>$adminId,'account'=>$pa,'pwd'=>$admin['pwd'],'real_name'=>'Normal78',
  'roles'=>(string)$adminRoleId,'add_time'=>$at,'level'=>1,'status'=>1,'is_del'=>0,'login_count'=>m5pcp_int($loginDefault,0)+1,'last_time'=>$last,'last_ip'=>gethostbyname(gethostname())]);
 $user=m4upo_one($before['rows']['eb_user'],'uid',$owner['uid']);$actualUser=m4upo_one($after['rows']['eb_user'],'uid',$owner['uid']);
 $user['last_time']=$actualUser['last_time'];$user['last_ip']=gethostbyname(gethostname());m4upo_time($actualUser['last_time'],$calls['customer']);
 m4up_expect($actualUser===$user,'UI_WINDOW_BUYER_ONLY_ACTUAL_LOGIN_STATS');
 return ['eb_marketplace_role'=>array_merge($before['rows']['eb_marketplace_role'],[$wantedRole]),
 'eb_marketplace_employee'=>array_merge($before['rows']['eb_marketplace_employee'],[$wantedEmployee]),
 'eb_marketplace_password_setup'=>array_merge($before['rows']['eb_marketplace_password_setup'],[$wantedSetup]),
 'eb_system_role'=>array_merge($before['rows']['eb_system_role'],[$wantedRoleAdmin]),
 'eb_system_admin'=>array_merge($before['rows']['eb_system_admin'],[$wantedAdmin]),
 'eb_user'=>m4ar_replaced($before['rows']['eb_user'],'uid',$owner['uid'],$user)];
}
function m4upo_database(array $before,array $after,array $expectedRows):void
{
 $expected=$before['database'];$counts=['eb_system_menus'=>2,'eb_marketplace_role'=>1,'eb_marketplace_employee'=>1,
 'eb_marketplace_password_setup'=>1,'eb_system_role'=>1,'eb_system_admin'=>1,'eb_user'=>0];
 m4c_entry_keys($expectedRows,array_keys($counts));
 foreach($counts as$table=>$n){$old=$before['database']['tables'][$table];$actual=$after['database']['tables'][$table];
  m4up_expect(m4fn_row_multiset($after['rows'][$table])===m4fn_row_multiset($expectedRows[$table])
   &&$actual['projection']===m4fn_projection($expectedRows[$table]),'UI_WINDOW_EVERY_PERMITTED_NATIVE_ROW_INDEPENDENTLY_EXACT');
  if($n===0)m4up_expect($actual['metadata']===$old['metadata']&&$actual['ddl']===$old['ddl'],'UI_WINDOW_LOGIN_NO_SCHEMA_COUNTER_CHANGE');
  else{$v=m5pcp_u64($old['metadata']['table']['AUTO_INCREMENT']);$next=bcadd($v,(string)$n,0);
   m4up_expect(m5pcp_u64($actual['metadata']['table']['AUTO_INCREMENT'])===$next,'UI_WINDOW_EXACT_SIX_NATIVE_COUNTER_ADVANCES');
   $meta=$old['metadata'];$meta['table']['AUTO_INCREMENT']=$actual['metadata']['table']['AUTO_INCREMENT'];
   m4up_expect($meta===$actual['metadata'],'UI_WINDOW_FULL_METADATA_ONLY_NATIVE_COUNTER');
   $oldOption=' AUTO_INCREMENT='.$v;$newOption=' AUTO_INCREMENT='.$next;
   if(str_contains($old['ddl'],$oldOption)){m4up_expect(substr_count($old['ddl'],$oldOption)===1,'UI_WINDOW_ONE_DDL_COUNTER');
    $ddl=str_replace($oldOption,$newOption,$old['ddl']);}
   else{m4up_expect($v==='1'&&substr_count($old['ddl'],' DEFAULT CHARSET=')===1,'UI_WINDOW_INITIAL_OMITTED_UNIT_COUNTER');
    $ddl=str_replace(' DEFAULT CHARSET=',$newOption.' DEFAULT CHARSET=',$old['ddl']);}
   m4up_expect($ddl===$actual['ddl'],'UI_WINDOW_FULL_DDL_ONLY_NATIVE_COUNTER');
  }
  m4up_expect($actual['projected_columns']===$old['projected_columns'],'UI_WINDOW_TARGET_COLUMN_ORDER_EXACT');
  $expected['tables'][$table]=$actual;
 }
 m4up_expect($after['database']===$expected,'UI_WINDOW_ALL_OTHER_TABLES_PAYMENTS_SCHEMA_MODES_COUNTERS_UNCHANGED');
 foreach($before['rows']as$table=>$rows)if(!isset($expectedRows[$table]))
  m4up_expect(m4fn_row_multiset($after['rows'][$table])===m4fn_row_multiset($rows),'UI_WINDOW_ALL_NON_TARGET_FULL_NATIVE_ROWS_UNCHANGED');
}
function m4upo_native_redis(string $key,string $type,mixed $expected,array $after):array
{
 $r=audit_redis();$actualType=$r->rawCommand('EVAL_RO',"return redis.call('TYPE',KEYS[1]).ok",1,$key);
 m4up_expect($actualType===$type,'UI_WINDOW_EXACT_ALLOWED_NATIVE_REDIS_TYPE');
 if($type==='string')m4up_expect($r->get($key)===$expected,'UI_WINDOW_INDEPENDENT_EXACT_SERIALIZED_REDIS_VALUE');
 elseif($type==='hash'){$actual=$r->hGetAll($key);ksort($actual,SORT_STRING);ksort($expected,SORT_STRING);m4up_expect($actual===$expected,'UI_WINDOW_INDEPENDENT_EXACT_SESSION_FIELDS');}
 elseif($type==='set'){$actual=$r->sMembers($key);sort($actual,SORT_STRING);sort($expected,SORT_STRING);m4up_expect($actual===$expected,'UI_WINDOW_INDEPENDENT_COMPLETE_TAG_MEMBERS');}
 else m4up_expect(false,'UI_WINDOW_ONLY_THREE_ALLOWED_PRODUCER_TYPES');
 $entry=$after['redis']['keys'][hash('sha256',$key)]??null;
 $dump=$type==='set'?null:$r->dump($key);$hash=$type==='set'?hash('sha256',"set\0".serialize($expected)):hash('sha256',$dump);
 m4up_expect(is_array($entry)&&$entry['type']===$type&&$entry['sha256']===$hash&&$entry['expires']===$r->rawCommand('PEXPIRETIME',$key),
  'UI_WINDOW_NATIVE_FULL_VALUE_AND_ABSOLUTE_DEADLINE_STABLE');return $entry;
}
function m4upo_warm_values(\think\App $app):array
{
 $names=['site_name','site_url','tourist_avatar','site_logo','site_logo_square','nncnL_crmeb_copyright','login_logo','queue_open',
 'wechat_open_app_id','nncnL_crmeb_copyright_image','custom_admin_js','param_filter_type','param_filter_data','new_order_audio_link',
 'model_checkbox','upload_type','image_thumb_status','member_func_status','member_card_status','member_price_status','store_brokerage_binding_status',
 'store_brokerage_binding_time','system_delivery_time','h5_avatar','offline_postage','integral_ratio','offline_pay_status','balance_func_status',
 'yue_pay_status','pay_weixin_open','friend_pay_status','store_self_mention','ali_pay_status','invoice_func_status','special_invoice_status'];$known=[];
 foreach($names as$n)$known['system_config_'.$n]=['value'=>$app->make(\app\services\system\config\SystemConfigServices::class)->getConfigValue($n),'ttl'=>0];
 $code=$app->make(\app\services\system\lang\LangCodeServices::class);$type=$app->make(\app\services\system\lang\LangTypeServices::class);
 $country=$app->make(\app\services\system\lang\LangCountryServices::class);$range=$type->value(['is_default'=>1],'file_name');
 $typeId=$country->value(['code'=>'zh-CN'],'type_id')?:1;$types=$type->getColumn(['status'=>1,'is_del'=>0],'file_name','id');
 m4up_expect($range==='zh-CN'&&($types[$typeId]??null)==='zh-CN','UI_WINDOW_INDEPENDENT_INSTALLED_LANGUAGE');
 foreach(['range_name'=>$range,'sys_lang_source_map'=>$code->getColumn(['type_id'=>1],'code','remarks'),'type_id_zh-CN'=>$typeId,
  'lang_type_data'=>$types,'lang_zh_CN'=>$code->getColumn(['type_id'=>$typeId],'lang_explain','code')]as$n=>$v)$known[$n]=['value'=>$v,'ttl'=>$n==='range_name'?0:3600];
 return $known;
}
function m4upo_redis(\think\App $app,array $before,array $after,array $actors,#[\SensitiveParameter] array $credentials):void
{
 $expected=$before['redis']['keys'];$crmeb='c:CRMEB:'.md5('crmeb');$api='c:CRMEB:'.md5('api');$members=$before['tag_members'][$crmeb];
 foreach(m4upo_warm_values($app)as$name=>$item){$key='c:'.$name;$members[]=$key;
  if($item['value']===null&&audit_redis()->get($key)===false)continue;
  $entry=m4upo_native_redis($key,'string',m4tr_cache_wire($item['value']),$after);
  if($item['ttl']===0)m4up_expect($entry['expires']===-1,'UI_WINDOW_CONFIG_PERMANENT');
  elseif(($before['redis']['keys'][hash('sha256',$key)]??null)!==$entry)
   m4up_expect($entry['expires']>=$before['redis']['time_ms']+1&&$entry['expires']<=$after['redis']['time_ms']+3600001,'UI_WINDOW_LANGUAGE_REAL_DEADLINE');
  // TagSet.remember appends each requested name on both cache hit and miss.
  $expected[hash('sha256',$key)]=$entry;
 }
 $members=array_values(array_unique($members,SORT_STRING));sort($members,SORT_STRING);
 $expected[hash('sha256',$crmeb)]=m4upo_native_redis($crmeb,'set',$members,$after);
 $config=audit_config();$calls=m4upo_calls($credentials);
 foreach(['merchant'=>'merchantapi','platform'=>'adminapi']as$kind=>$application){
  $actor=$actors[$kind];$token=$actor['token'];$claims=(array)\Firebase\JWT\JWT::decode($token,$config['signing_key'],['HS256']);
  $bind=\crmeb\services\AuthConfigGuard::authConfig()['apps'][$application];$id=$actor['id'];
  m4up_expect(($claims['auth_schema']??null)===2&&$claims['session_epoch']===$config['auth']['session_epoch']
   &&$claims['iss']===$config['auth']['issuer']&&$claims['aud']===$bind['audience']&&($claims['jti']->type??null)===$bind['type']
   &&($claims['jti']->id??null)===(string)$id&&$claims['nbf']===$claims['iat']&&$claims['exp']===$claims['iat']+$bind['ttl'],
   'UI_WINDOW_GENUINE_ISSUED_V2_SIGNED_IDENTITY');m4upo_time($claims['iat'],$calls[$kind]);
  $sid=$claims['sid'];$version=$claims['session_version'];
  m4up_expect(preg_match('/\A[a-f0-9]{32}\z/D',$sid)===1&&preg_match('/\A[a-f0-9]{32}\z/D',$version)===1,'UI_WINDOW_REAL_SESSION_RANDOM_IDENTITIES');
  $prefix='auth:v2:'.$config['auth']['session_epoch'].':'.$application.':';$account=$prefix.'account:'.$id;$key=$prefix.'session:'.$sid;
  foreach([$account,$key]as$n)m4up_expect(!isset($before['redis']['keys'][hash('sha256',$n)]),'UI_WINDOW_ACTUAL_FRESH_ACCOUNT_SESSION_KEYS');
  $table=$kind==='merchant'?'eb_marketplace_employee':'eb_system_admin';$row=m4upo_one($after['rows'][$table],'id',$id);
  $a=m4upo_native_redis($account,'hash',['blocked'=>'0','initialized'=>'1','session_version'=>$version],$after);
  m4up_expect($a['expires']===-1,'UI_WINDOW_ACCOUNT_NO_EXPIRY');$expected[hash('sha256',$account)]=$a;
  $wanted=['subject_id'=>(string)$id,'type'=>$bind['type'],'audience'=>$bind['audience'],'session_version'=>$version,
   'token_digest'=>hash('sha256',$token),'credential_stamp'=>hash_hmac('sha256',$application.'|'.$id.'|'.$row['pwd'],$config['auth']['stamp_key']),
   'expires_at'=>(string)$claims['exp'],'status'=>'active'];$e=m4upo_native_redis($key,'hash',$wanted,$after);
  m4up_expect(abs($e['expires']-($claims['exp']+60)*1000)<=1,'UI_WINDOW_V2_NATIVE_ABSOLUTE_DEADLINE');$expected[hash('sha256',$key)]=$e;
 }
 $token=$actors['customer']['token'];$claims=(array)\Firebase\JWT\JWT::decode($token,$config['signing_key'],['HS256']);$uid=$actors['customer']['id'];
 m4up_expect(($claims['jti']->type??null)==='api'&&(string)($claims['jti']->id??null)===(string)$uid
  &&$claims['nbf']===$claims['iat']&&$claims['exp']-$claims['iat']===30*86400,'UI_WINDOW_GENUINE_LEGACY_CUSTOMER_SIGNED_IDENTITY');
 m4upo_time($claims['iat'],$calls['customer']);$ttl=$claims['exp']-$claims['iat']+60;$key='c:'.md5($token);
 m4up_expect(!isset($before['redis']['keys'][hash('sha256',$key)]),'UI_WINDOW_NEW_CUSTOMER_SESSION_KEY');
 $e=m4upo_native_redis($key,'string',serialize(['uid'=>$uid,'type'=>'api','token'=>$token,'exp'=>$ttl]),$after);
 m4up_expect($e['expires']>=$calls['customer'][0]*1000+$ttl*1000&&$e['expires']<=($calls['customer'][1]+1)*1000+$ttl*1000,'UI_WINDOW_CUSTOMER_REAL_ABSOLUTE_DEADLINE');
 $expected[hash('sha256',$key)]=$e;$apiMembers=array_values(array_unique(array_merge($before['tag_members'][$api],[$key]),SORT_STRING));sort($apiMembers,SORT_STRING);
 $expected[hash('sha256',$api)]=m4upo_native_redis($api,'set',$apiMembers,$after);
 ksort($expected,SORT_STRING);m4up_expect($after['redis']['keys']===$expected,'UI_WINDOW_ALL_OTHER_REDIS_TYPES_VALUES_ABSOLUTE_DEADLINES_UNCHANGED');
 m4up_cold($app);
}
function m4upo_root(array $old,array $new,array $changes=[]):void
{
 $wanted=$old;foreach($changes as$k=>$v)$wanted[$k]=$v;m4up_expect($new===$wanted,'UI_WINDOW_EXACT_NATIVE_DIRECTORY_IDENTITY');
}
function m4upo_file(array $entry,string $raw,int $mode=0600):void
{
 m4up_expect($entry['kind']==='file'&&$entry['bytes']===strlen($raw)&&$entry['sha256']===hash('sha256',$raw)
  &&$entry['mode']===$mode&&$entry['uid']===posix_geteuid()&&$entry['gid']===posix_getegid()&&$entry['nlink']===1
  &&$entry['dev']>=0&&$entry['ino']>0,'UI_WINDOW_ACTUAL_EXACT_FILE_BYTES_MODE_OWNER');
}
function m4upo_files(array $before,array $after,array $source,array $writer,#[\SensitiveParameter] array $credentials,array $actors):void
{
 $root='/run/audit-fixtures';$a=$before['files'][$root];$z=$after['files'][$root];
 m4up_expect(is_array($a)&&is_array($z)&&!isset($a['ms4-ui'])&&!isset($a['ms4-ui/credentials.json']),'UI_WINDOW_FRESH_PRIVATE_HANDOFF_PATH');
 $wanted=array_keys($a);$wanted[]='ms4-ui';$wanted[]='ms4-ui/credentials.json';sort($wanted,SORT_STRING);
 m4up_expect(array_keys($z)===$wanted,'UI_WINDOW_ONLY_TWO_NEW_PRIVATE_ENTRIES');
 foreach($a as$name=>$entry)if($name!=='')m4up_expect($z[$name]===$entry,'UI_WINDOW_EVERY_OLD_PRIVATE_INPUT_FILE_STAT_HASH_PRESERVED');
 m4upo_root($a[''],$z[''],['nlink'=>$a['']['nlink']+1]);
 $directory=$z['ms4-ui'];m4up_expect($directory['kind']==='directory'&&$directory['mode']===0700&&$directory['uid']===posix_geteuid()
  &&$directory['gid']===posix_getegid()&&$directory['dev']===$a['']['dev']&&$directory['ino']>0&&$directory['nlink']===2,'UI_WINDOW_NEW_OWNED_PRIVATE_DIRECTORY');
 $raw=json_encode($credentials,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
 $entry=$z['ms4-ui/credentials.json'];m4upo_file($entry,$raw);
 m4c_entry_keys($writer,['path','bytes','sha256','mode','uid','gid','dev','ino','nlink','directory_mode']);
 m4up_expect($writer===['path'=>$root.'/ms4-ui/credentials.json','bytes'=>$entry['bytes'],'sha256'=>$entry['sha256'],'mode'=>'0600',
  'uid'=>$entry['uid'],'gid'=>$entry['gid'],'dev'=>$entry['dev'],'ino'=>$entry['ino'],'nlink'=>$entry['nlink'],'directory_mode'=>'0700'],
  'UI_WINDOW_ACTUAL_FIXED_WRITER_DESCRIPTOR');
 $p=M4AR_PRIVATE;$x=$before['files'][$p];$y=$after['files'][$p];
 m4up_expect(is_array($x)&&is_array($y)&&array_keys($x)===['']&&array_keys($y)===[''],'UI_WINDOW_PRIVATE_EVIDENCE_REMAINS_EMPTY');
 m4upo_root($x[''],$y[''],['mode'=>0700]);
 $p='/workspace/crmeb/public';$x=$before['files'][$p];$y=$after['files'][$p];$names=array_keys($x);
 foreach(['index.php','router.php','install.lock']as$n){m4up_expect(!isset($x[$n]),'UI_WINDOW_FRESH_OWN_PUBLIC_MATERIAL');$names[]=$n;}
 sort($names,SORT_STRING);m4up_expect(array_keys($y)===$names,'UI_WINDOW_ONLY_THREE_PUBLIC_PREPARATION_FILES');
 foreach($x as$n=>$entry)if($n!=='')m4up_expect($y[$n]===$entry,'UI_WINDOW_ALL_ORIGINAL_PUBLIC_FILES_UNCHANGED');
 m4upo_root($x[''],$y[''],['mode'=>0700]);
 foreach(['index.php','router.php']as$n)m4upo_file($y[$n],file_get_contents('/source/crmeb/public/'.$n));
 m4upo_file($y['install.lock'],'MS4_CUSTOMER_HTTP_INSTALLED '.$source['run_id']."\n");
 $p='/workspace/crmeb/runtime';$x=$before['files'][$p];$y=$after['files'][$p];
 m4up_expect(is_array($x)&&array_keys($x)===['']&&array_keys($y)===['','ms4-aftersale-review-http.stderr','ms4-aftersale-review-http.stdout'],
  'UI_WINDOW_ONLY_TWO_OWN_CHILD_LOG_FILES');m4upo_root($x[''],$y[''],['mode'=>0700]);
 $secrets=[];foreach($credentials as$c)$secrets[]=$c['password'];foreach(['customer','merchant','platform']as$k)$secrets[]=$actors[$k]['token'];
 foreach(['ms4-aftersale-review-http.stdout','ms4-aftersale-review-http.stderr']as$n){$e=$y[$n];
  m4up_expect($e['kind']==='file'&&$e['bytes']<=16777216&&$e['mode']===0600&&$e['uid']===posix_geteuid()&&$e['nlink']===1,'UI_WINDOW_BOUNDED_OWN_PRIVATE_CHILD_STREAM');
  $raw=file_get_contents($p.'/'.$n);m4up_expect(is_string($raw)&&strlen($raw)===$e['bytes']&&hash('sha256',$raw)===$e['sha256'],'UI_WINDOW_FULL_CHILD_STREAM_READBACK');
  foreach($secrets as$v)m4up_expect(is_string($v)&&$v!==''&&!str_contains($raw,$v),'UI_WINDOW_NO_CREDENTIAL_OR_TOKEN_BYTES_IN_LOGS');
 }
 $p='/tmp/ms4-fulfillment-normal-'.$source['run_id'];$x=$before['files'][$p];$y=$after['files'][$p];
 m4up_expect($x===null&&is_array($y)&&array_keys($y)===['']&&$y['']['kind']==='directory'&&$y['']['mode']===0700
  &&$y['']['uid']===posix_geteuid()&&$y['']['gid']===posix_getegid()&&$y['']['nlink']===2,'UI_WINDOW_ONLY_EMPTY_OWN_APP_RUNTIME_DIRECTORY');
 $closed=$GLOBALS['m4ar_child_proof']??null;
 if(is_array($closed)&&($closed['stopped']??false)===true){$streams=$closed['streams'];
  m4up_expect(is_array($streams)&&count($streams)===2,'UI_WINDOW_REAL_CLOSED_STREAM_DESCRIPTORS');
  foreach(['ms4-aftersale-review-http.stdout','ms4-aftersale-review-http.stderr']as$i=>$n){
   $e=$after['files']['/workspace/crmeb/runtime'][$n];
   m4up_expect($streams[$i]===['bytes'=>$e['bytes'],'sha256'=>$e['sha256']],'UI_WINDOW_SAME_CHILD_CLOSED_FULL_LOG_BYTES');}
 }
 unset($raw,$secrets,$credentials);
}
function m4upo_verify(PDO $db,\think\App $app,array $source,array $owner,array $before,array $actors,
 #[\SensitiveParameter] array $credentials,array $writer,array $menus,array $interval):array
{
 m4up_phase('independent_complete_prepare_window');$after=m4upo_snapshot($db);
 $expected=m4upo_actor_rows($before,$after,$owner,$actors,$credentials,$interval);
 $expected['eb_system_menus']=m4upo_menu_rows($before,$after,$menus);
 m4upo_database($before,$after,$expected);m4upo_redis($app,$before,$after,$actors,$credentials);
 m4upo_files($before,$after,$source,$writer,$credentials,$actors);m4fn_inputs_unchanged($owner['inputs']);
 // A second full independent read prevents the oracle's own direct service/Redis/file reads from masking mutations.
 $again=m4upo_snapshot($db);
 m4up_expect($again['database']===$after['database']&&$again['rows']===$after['rows']
  &&$again['redis']['keys']===$after['redis']['keys']&&$again['tag_members']===$after['tag_members']
  &&$again['files']===$after['files'],'UI_WINDOW_ORACLE_ITSELF_FULL_ZERO_READ_DELTA');
 return ['before'=>m4upo_descriptor($before),'after'=>m4upo_descriptor($after)];
}
