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


/** Explicit business observer additions only. Original prepare functions above remain unchanged. */
function m4uwo_plain(mixed $v):mixed
{if($v instanceof stdClass)$v=get_object_vars($v);if(is_array($v))foreach($v as$k=>$x)$v[$k]=m4uwo_plain($x);return $v;}
function m4uwo_canonical(mixed $v):mixed
{
    if($v instanceof stdClass){$a=get_object_vars($v);ksort($a,SORT_STRING);foreach($a as$k=>$x)$a[$k]=m4uwo_canonical($x);return(object)$a;}
    if(is_array($v)){if(!array_is_list($v)){ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=m4uwo_canonical($x);return(object)$v;}
        foreach($v as$k=>$x)$v[$k]=m4uwo_canonical($x);}
    return $v;
}
function m4uwo_json(mixed $v):string
{return json_encode(m4uwo_canonical($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function m4uwo_steps():array
{return ['login-customer','login-merchant','login-platform','n1-initial-read','n1-private-evidence-upload','n1-money-only-apply',
'n1-owned-private-preview-read','n1-merchant-approve','ship-q2','receive-q2-partial','receive-q2-all-eligible','ship-q1',
'receive-q1-all-eligible','n2-public-image-upload','n2-review-submit','n2-pending-public-read','n2-platform-moderate',
'n2-merchant-reply','n2-public-review-read','n2-public-image-read'];}
function m4uwo_tree(string $root):?array
{
    clearstatcache(true,$root);if(!file_exists($root)){m4uib_assert(!is_link($root),'OBS_NO_DANGLING_ROOT');return null;}
    $out=[];
    $walk=static function(string $path,string $relative)use(&$walk,&$out):void{
        clearstatcache(true,$path);$s=lstat($path);m4uib_assert(is_array($s)&&!is_link($path)&&realpath($path)===$path,'OBS_REAL_TREE_ENTRY');
        $kind=$s['mode']&0170000;m4uib_assert(in_array($kind,[0040000,0100000],true),'OBS_ONLY_DIRECTORY_OR_REGULAR');
        $r=['kind'=>$kind===0040000?'directory':'file','dev'=>$s['dev'],'ino'=>$s['ino'],'mode'=>$s['mode']&07777,'uid'=>$s['uid'],'gid'=>$s['gid'],'nlink'=>$s['nlink']];
        if($kind===0100000){$f=m4uib_file($path,67108864);m4uib_assert($f['stat']===m4uib_stat($s)&&$s['nlink']===1,'OBS_SAME_SINGLELINK_TREE_FILE');
            $r+=['bytes'=>$f['descriptor']['bytes'],'sha256'=>$f['descriptor']['sha256'],'mtime'=>$s['mtime'],'ctime'=>$s['ctime']];$out[$relative]=$r;return;}
        $out[$relative]=$r;$names=scandir($path);m4uib_assert(is_array($names),'OBS_COMPLETE_DIRECTORY_LIST');
        foreach($names as$name){if($name==='.'||$name==='..')continue;m4uib_assert($name!==''&&!str_contains($name,"\0")&&!str_contains($name,'/'),'OBS_TREE_LEAF_NAME');
            $walk($path.'/'.$name,$relative===''?$name:$relative.'/'.$name);}
        clearstatcache(true,$path);$again=lstat($path);m4uib_assert(is_array($again)&&!is_link($path)&&m4uib_stat($again)===m4uib_stat($s)&&scandir($path)===$names,'OBS_COMPLETE_DIRECTORY_SET_STAT_STABLE');
    };$walk($root,'');ksort($out,SORT_STRING);return $out;
}
function m4uwo_redis():array
{
    $lua=<<<'LUA'
local now=redis.call('TIME');local names=redis.call('KEYS','*');table.sort(names);local rows={}
for _,key in ipairs(names) do
 local t=redis.call('TYPE',key).ok;local value=false
 if t=='string' then value=redis.call('GET',key) elseif t=='hash' then value=redis.call('HGETALL',key) elseif t=='set' then value=redis.call('SMEMBERS',key) end
 rows[#rows+1]={key,t,redis.call('DUMP',key),redis.call('PEXPIRETIME',key),value}
end
return {now,rows}
LUA;
    $redis=audit_redis();try{$raw=$redis->rawCommand('EVAL_RO',$lua,0);}finally{$redis->close();}
    m4uib_assert(is_array($raw)&&count($raw)===2&&is_array($raw[0])&&count($raw[0])===2&&is_array($raw[1]),'OBS_ATOMIC_COMPLETE_REDIS');
    $time=bcadd(bcmul((string)$raw[0][0],'1000',0),bcdiv((string)$raw[0][1],'1000',0),0);$keys=[];$native=[];$previous=null;
    foreach($raw[1]as$r){m4uib_assert(is_array($r)&&count($r)===5&&is_string($r[0])&&($previous===null||strcmp($previous,$r[0])<0)
        &&is_string($r[1])&&$r[1]!==''&&$r[1]!=='none'&&is_string($r[2])&&$r[2]!==''&&is_int($r[3])&&($r[3]===-1||$r[3]>0),'OBS_NATIVE_REDIS_ROW');
        [$key,$type,$dump,$expires,$value]=$r;$previous=$key;
        if($type==='hash'){$pairs=$value;m4uib_assert(is_array($pairs)&&count($pairs)%2===0,'OBS_HASH_PAIRS');$value=[];
            for($i=0;$i<count($pairs);$i+=2){m4uib_assert(is_string($pairs[$i])&&is_string($pairs[$i+1])&&!array_key_exists($pairs[$i],$value),'OBS_HASH_UNIQUE_FIELDS');$value[$pairs[$i]]=$pairs[$i+1];}ksort($value,SORT_STRING);}
        if($type==='set'){m4uib_assert(is_array($value)&&array_is_list($value),'OBS_SET_NATIVE_MEMBERS');foreach($value as$v)m4uib_assert(is_string($v),'OBS_SET_STRING_MEMBER');
            m4uib_assert(count($value)===count(array_unique($value,SORT_STRING)),'OBS_SET_UNIQUE');sort($value,SORT_STRING);}
        if($type==='string')m4uib_assert(is_string($value),'OBS_STRING_NATIVE_BYTES');
        $hash=$type==='set'?hash('sha256',"set\0".serialize($value)):hash('sha256',$dump);$id=hash('sha256',$key);
        $keys[$id]=['type'=>$type,'sha256'=>$hash,'expires'=>$expires];
        $native[$key]=['type'=>$type,'dump_sha256'=>hash('sha256',$dump),'expires'=>$expires,'value'=>$value];unset($dump,$pairs,$r);
    }ksort($keys,SORT_STRING);ksort($native,SORT_STRING);unset($raw);return ['time_ms'=>$time,'keys'=>$keys,'native'=>$native];
}
function m4uwo_snapshot(PDO $db):array
{
    $s=m4upo_snapshot($db);$redis=m4uwo_redis();m4uib_assert($s['redis']['keys']===$redis['keys'],'OBS_COMPLETE_REDIS_TWO_NATIVE_READS_SAME_STATE');
    $s['native_redis']=$redis['native'];$s['redis']['time_ms']=$redis['time_ms'];
    foreach(array_keys($s['files'])as$root)$s['files'][$root]=m4uwo_tree($root);
    $s['files'][M4UIB_CONTROL]=m4uwo_tree(M4UIB_CONTROL);$s['files']['/tmp']=m4uwo_tree('/tmp');
    $f=m4uib_file('/run/audit-app/config.json');$s['files']['/run/audit-app/config.json']=[''=>['kind'=>'file','dev'=>$f['stat']['dev'],'ino'=>$f['stat']['ino'],
        'mode'=>$f['stat']['mode']&07777,'uid'=>$f['stat']['uid'],'gid'=>$f['stat']['gid'],'nlink'=>$f['stat']['nlink'],
        'bytes'=>$f['descriptor']['bytes'],'sha256'=>$f['descriptor']['sha256'],'mtime'=>$f['stat']['mtime'],'ctime'=>$f['stat']['ctime']]];
    $s['log_raw']=[];foreach(M4UIB_LOGS as$p){$f=m4uib_file($p,16777216,true);$relative=basename($p);$r=$s['files']['/workspace/crmeb/runtime'][$relative]??null;
        m4uib_assert(is_array($r)&&$r['dev']===$f['stat']['dev']&&$r['ino']===$f['stat']['ino']&&$r['bytes']===$f['descriptor']['bytes']
            &&$r['sha256']===$f['descriptor']['sha256']&&$r['mode']===0600&&$r['uid']===0&&$r['nlink']===1,'OBS_SAME_COMPLETE_PRIVATE_LOG');$s['log_raw'][$p]=$f['raw'];}
    return $s;
}
function m4uwo_same(array $a,array $b):void
{
    m4uib_assert($a['database']===$b['database']&&m4uib_same($a['rows'],$b['rows'])&&$a['redis']['keys']===$b['redis']['keys']
        &&$a['native_redis']===$b['native_redis']&&$a['tag_members']===$b['tag_members']&&$a['files']===$b['files']&&$a['log_raw']===$b['log_raw'],'OBS_FULL_ALLSTATE_ZERO_EXCEPT_REDIS_TIME');
}
function m4uwo_ledger(array $s,array $binding,int $index,string $point):array
{
    $tables=[];foreach($s['database']['names']as$n){$t=$s['database']['tables'][$n];$tables[$n]=['metadata_sha256'=>hash('sha256',serialize($t['metadata'])),
        'ddl_sha256'=>hash('sha256',$t['ddl']),'projected_columns_sha256'=>hash('sha256',serialize($t['projected_columns'])),'projection'=>$t['projection']];}
    $keys=[];foreach($s['native_redis']as$n=>$r)$keys[hash('sha256',(string)$n)]=$s['redis']['keys'][hash('sha256',(string)$n)]+['dump_sha256'=>$r['dump_sha256']];ksort($keys,SORT_STRING);
    return ['schema'=>1,'scope'=>'MS4_UI_COMPLETE_NATIVE_WINDOW_LEDGER','binding'=>$binding,'index'=>$index,'point'=>$point,
        'database'=>['names'=>$s['database']['names'],'namespace_sha256'=>hash('sha256',serialize($s['database']['names'])),
            'schema_modes_sha256'=>hash('sha256',serialize([$s['database']['modes'],$s['database']['increment']])),'tables'=>(object)$tables],
        'redis'=>['time_ms'=>$s['redis']['time_ms'],'keys'=>(object)$keys],'files'=>['roots'=>(object)$s['files']]];
}
function m4uwo_gateway_ip(array $s):string
{
    $p='/run/audit/evidence/ui-gateway-current.json';if(isset($s['observer_gateway_current_sha256']))m4uib_assert(m4uib_hash($p)===$s['observer_gateway_current_sha256'],'OBS_ORIGINAL_GATEWAY_CURRENT_INPUT_PRESERVED');$current=m4uib_json($p);
    m4uib_keys($current,['schema','scope','run_id','source_sha','freeze_sha256','container_id','native_created_sha256','projection','native','port']);
    m4uib_assert($current['schema']===1&&$current['scope']==='MS4_UI_GATEWAY_CURRENT_NATIVE'&&$current['run_id']===$s['run_id']
        &&$current['source_sha']===$s['source_sha']&&$current['freeze_sha256']===$s['freeze_sha256'],'OBS_GATEWAY_SAME_CURRENT_SCOPE');
    $created=m4uib_json('/run/audit/evidence/ui-gateway-created.json');m4uib_assert($current['container_id']===$created['id']
        &&$current['native_created_sha256']===m4uib_hash('/run/audit/evidence/ui-gateway-created.json'),'OBS_GATEWAY_CURRENT_CREATED_HASH');
    $native=$current['native'];$projection=$current['projection'];
    m4uib_assert(preg_match('~\A/run/audit/evidence/copy-native-[0-9]{4}\.stdout\z~D',$projection['path'])===1,'OBS_ACTUAL_HOST_FINITE_NUMBERED_NATIVE_PROJECTION');
    $stem=substr($projection['path'],0,-7);$createdNative=m4uib_json($stem.'.created.json');$terminal=m4uib_json($stem.'.terminal.json');
    m4uib_assert(m4uib_same($createdNative,['label'=>'web-gateway-current','argv'=>$native['argv'],'pid'=>$native['pid']])&&m4uib_same($terminal,$native),'OBS_ACTUAL_HOST_SAME_CREATED_TERMINAL_PID_ARGV');
    $stderr=m4uib_file($stem.'.stderr');m4uib_assert($stderr['descriptor']['bytes']===0&&$stderr['descriptor']['sha256']===hash('sha256',''),'OBS_ACTUAL_HOST_COMPLETE_EMPTY_NATIVE_STDERR');
    m4uib_keys($current['port'],['port','origin','rows','native','projection']);
    m4uib_assert($current['port']['port']===$s['freeze']['ui_web_port']&&$current['port']['origin']===$s['freeze']['ui_web_origin'],'OBS_ORIGINAL_FROZEN_GATEWAY_PORT');
    $raw=m4uib_native($native,$projection,['docker','inspect','--type','container','--format',M4UIB_INSPECT_FORMAT,$created['id']],'web-gateway-current');
    $actual=json_decode($raw,true,64,JSON_THROW_ON_ERROR);unset($raw);m4uib_keys($actual,M4UIB_NATIVE_KEYS);m4uib_same_created($created,$actual);
    m4uib_assert($actual['id']===$created['id']&&$actual['status']==='running'&&$actual['pid']>0&&$actual['restart_count']===0,'OBS_GATEWAY_REAL_POSTSTART_NATIVE');
    $found=[];foreach($actual['networks']as$n)if(($n['NetworkID']??null)===$s['context']['network_id'])$found[]=$n['IPAddress'];
    m4uib_assert(count($found)===1&&filter_var($found[0],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false,'OBS_GATEWAY_ORIGINAL_INTERNAL_NETWORK_PEER');return $found[0];
}
function m4uwo_wire(string $name,mixed $input,array $state):array
{
    m4uib_assert($input instanceof stdClass,'OBS_VERIFY_PUBLIC_WIRE_EVENT_OBJECT');$p=get_object_vars($input);m4uib_keys($p,['wire','browser_event']);
    m4uib_assert($p['wire'] instanceof stdClass&&$p['browser_event'] instanceof stdClass,'OBS_WIRE_EVENT_RECORDS');
    $wire=get_object_vars($p['wire']);$event=get_object_vars($p['browser_event']);
    m4uib_keys($wire,['actor_role','method','path','body_public','body_public_sha256','response_status','response_public','response_public_sha256']);
    m4uib_keys($event,['schema','scope','binding','index','name','role','tab_id_sha256','started_at_ms','finished_at_ms','actions','requests','identity','DOM','diagnostic']);
    $names=m4uwo_steps();$i=array_search($name,$names,true)+1;m4uib_assert($event['schema']===1&&$event['scope']==='MS4_UI_NORMAL_BROWSER_STEP'
        &&$event['index']===$i&&$event['name']===$name&&$event['role']===$wire['actor_role']&&m4uib_hex($event['tab_id_sha256'])
        &&m4uib_same(m4uwo_plain($event['binding']),$state['binding'])
        &&is_int($event['started_at_ms'])&&is_int($event['finished_at_ms'])&&$event['finished_at_ms']>=$event['started_at_ms']
        &&is_array($event['actions'])&&$event['actions']!==[]&&is_array($event['requests'])&&($event['requests']!==[]||$name==='n2-public-image-read')&&$event['diagnostic']===null,'OBS_ACTUAL_SAFE_BROWSER_PHASE');
    m4uib_assert($wire['response_status']===200&&is_string($wire['method'])&&in_array($wire['method'],['GET','POST','PUT'],true)
        &&is_string($wire['path'])&&str_starts_with($wire['path'],'/')&&!str_contains($wire['path'],'://')&&!str_contains($wire['path'],'#')
        &&!str_contains($wire['path'],'..')&&!str_contains($wire['path'],'\\')&&strlen($wire['path'])<=2048
        &&hash('sha256',m4uwo_json($wire['body_public']))===$wire['body_public_sha256']&&hash('sha256',m4uwo_json($wire['response_public']))===$wire['response_public_sha256'],'OBS_SAFE_WIRE_CANONICAL_HASHES');
    $expectedRoles=['customer','merchant','platform','customer','customer','customer','customer','merchant','merchant','customer','customer','merchant','customer','customer','customer','public','platform','merchant','public','public'];
    m4uib_assert($wire['actor_role']===$expectedRoles[$i-1],'OBS_CASE_ROLE');
    foreach($event['requests']as$j=>$r){m4uib_assert($r instanceof stdClass,'OBS_BROWSER_REQUEST_OBJECT');$r=get_object_vars($r);
        m4uib_keys($r,['sequence','method','path','started_at_ms','finished_at_ms','http_status','app_status','request_public','response_public','request_public_sha256','response_public_sha256','authentication']);
        m4uib_assert($r['sequence']===$j&&is_int($r['started_at_ms'])&&is_int($r['finished_at_ms'])&&$r['started_at_ms']>=$event['started_at_ms']
            &&$r['finished_at_ms']>=$r['started_at_ms']&&$r['finished_at_ms']<=$event['finished_at_ms']&&$r['http_status']===200
            &&in_array($r['method'],['GET','POST','PUT'],true)&&is_string($r['path'])&&str_starts_with($r['path'],'/')
            &&!str_contains($r['path'],'://')&&!str_contains($r['path'],'..')&&!str_contains($r['path'],'#')
            &&hash('sha256',m4uwo_json($r['request_public']))===$r['request_public_sha256']&&hash('sha256',m4uwo_json($r['response_public']))===$r['response_public_sha256'],'OBS_COMPLETE_SAFE_ASSOCIATED_REQUEST');
        $r['authentication']=m4uwo_plain($r['authentication']);m4uib_keys($r['authentication'],['present','kind','subject_id']);
        // Public-step GUI logout includes authenticated precursor requests; each finite path is verified in the twenty-case oracle.
        $event['requests'][$j]=$r;
    }
    $matches=[];foreach($event['requests']as$i=>$r)if($r['method']===$wire['method']&&$r['path']===$wire['path']&&$r['request_public_sha256']===$wire['body_public_sha256']&&$r['response_public_sha256']===$wire['response_public_sha256'])$matches[]=$i;
    $historical=$name==='n2-public-image-read'&&$matches===[];
    if($historical)m4uws_image_reuse($state,$wire);else m4uib_assert(count($matches)===1,'OBS_UNIQUE_PRIMARY_WIRE_ACTUAL_REQUEST_JOIN');
    $primarySequence=$historical?null:$matches[0];
    $wire['body_is_empty_object']=$wire['body_public'] instanceof stdClass&&get_object_vars($wire['body_public'])===[];
    $wire['body_public']=m4uwo_plain($wire['body_public']);$wire['response_public']=m4uwo_plain($wire['response_public']);
    return ['wire'=>$wire,'event'=>$event,'primary_sequence'=>$primarySequence,'historical_image'=>$historical];
}
function m4uwo_logs(array $before,array $after,array $event,array $s):void
{
    $peer=m4uwo_gateway_ip($s);$requests=[];foreach($event['requests']as$r)if(preg_match('~\A/(?:api|merchantapi|adminapi)(?:/|$)~',$r['path'])===1)$requests[]=$r;
    $seen=[];$connections=[];
    // Exact known PHP8.2 built-in-server normal format only; unexpected framework text is incomplete.
    m4uib_assert(in_array(getenv('TZ'),[false,'','UTC','Etc/UTC'],true),'OBS_LOG_UTC_ENVIRONMENT');
    $zone=is_link('/etc/localtime')?readlink('/etc/localtime'):null;
    m4uib_assert(in_array($zone,['/usr/share/zoneinfo/Etc/UTC','/usr/share/zoneinfo/UTC'],true),'OBS_LOG_ACTUAL_SYSTEM_UTC');
    foreach(M4UIB_LOGS as$path){$old=$before['log_raw'][$path];$new=$after['log_raw'][$path];$leaf=basename($path);
        $a=$before['files']['/workspace/crmeb/runtime'][$leaf];$z=$after['files']['/workspace/crmeb/runtime'][$leaf];
        foreach(['kind','dev','ino','mode','uid','gid','nlink']as$k)m4uib_assert($a[$k]===$z[$k],'OBS_LOG_SAME_ORIGINAL_INODE_MODE_OWNER');
        m4uib_assert(strlen($new)<=16777216&&str_starts_with($new,$old),'OBS_LOG_COMPLETE_OLD_BYTE_PREFIX');$extra=substr($new,strlen($old));
        if($extra==='')continue;m4uib_assert(str_ends_with($extra,"\n"),'OBS_COMPLETE_NORMAL_LOG_LINES');
        foreach(explode("\n",rtrim($extra,"\n"))as$line){m4uib_assert(preg_match('~\A\[([A-Za-z]{3} [A-Za-z]{3} [ 0-9][0-9]? [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4})\] ([0-9.]+):([1-9][0-9]{0,4}) (Accepted|Closing|\[200\]: (GET|POST|PUT) (/[^\r\n ]*))\z~D',$line,$m)===1,'OBS_KNOWN_BOUNDED_ACCESS_LOG_FORMAT');
            m4uib_assert($m[2]===$peer&&(int)$m[3]<=65535,'OBS_ACTUAL_GATEWAY_PEER_LOG_SOURCE');$clock=DateTimeImmutable::createFromFormat('D M j H:i:s Y',preg_replace('/ +/',' ',$m[1]),new DateTimeZone('UTC'));
            m4uib_assert($clock instanceof DateTimeImmutable,'OBS_NATIVE_LOG_TIME_PARSE');$at=$clock->getTimestamp()*1000;
            m4uib_assert($at+1000>$event['started_at_ms']&&$at<=$event['finished_at_ms'],'OBS_LOG_ACTUAL_PHASE_TIME');$connection=$path.'|'.$m[2].':'.$m[3];
            if($m[4]==='Accepted'){m4uib_assert(!isset($connections[$connection]),'OBS_NORMAL_ACCEPT_ONCE');$connections[$connection]=0;}
            elseif($m[4]==='Closing'){m4uib_assert(($connections[$connection]??null)===1,'OBS_NORMAL_CLOSE_AFTER_ONE_RESPONSE');unset($connections[$connection]);}
            else{m4uib_assert(($connections[$connection]??null)===0,'OBS_ONE_NORMAL_RESPONSE_AFTER_ACCEPT');$match=[];
                foreach($requests as$i=>$r)if(!isset($seen[$i])&&$r['method']===$m[5]&&$r['path']===$m[6]&&$at+1000>$r['started_at_ms']&&$at<=$r['finished_at_ms'])$match[]=$i;
                m4uib_assert(count($match)===1,'OBS_LOG_EXACT_ASSOCIATED_NORMAL_REQUEST');$seen[$match[0]]=true;$connections[$connection]=1;}
        }
    }m4uib_assert($connections===[]&&count($seen)===count($requests),'OBS_EVERY_NORMAL_REQUEST_EXACT_COMPLETE_LOG_CYCLE');
    $a=$before['files']['/workspace/crmeb/runtime'];$z=$after['files']['/workspace/crmeb/runtime'];
    foreach(M4UIB_LOGS as$p){$leaf=basename($p);unset($a[$leaf],$z[$leaf]);}
    m4uib_assert($a===$z,'OBS_EVERY_OTHER_RUNTIME_TREE_PRESERVED');
}
function m4uwo_preservation(array $s,array $binding):array
{
    $source=[];foreach($s['source_files']as$r)$source[]=['path'=>'/workspace/'.$r['path'],'bytes'=>$r['bytes'],'sha256'=>$r['sha256']];
    $old=[];foreach($s['prerequisites_sha256']as$n=>$hash)$old[]=['path'=>'/run/audit/evidence/'.$n,'bytes'=>m4uib_file('/run/audit/evidence/'.$n)['descriptor']['bytes'],'sha256'=>$hash];
    $private=[];foreach(['/run/audit-fixtures','/var/lib/crmeb-aftersale-evidence']as$r)$private[$r]=m4uwo_tree($r);
    $materials=m4uib_descriptor($s['public_materials'],'/run/audit/evidence/ui-public-materials.json');
    $out=[];foreach(['source'=>['MS4_UI_WINDOW_SOURCE_PRESERVED',$source],'predecessors'=>['MS4_UI_WINDOW_PREDECESSORS_PRESERVED',$old],
        'private_inputs'=>['MS4_UI_WINDOW_PRIVATE_INPUTS_PRESERVED',$private],'public_materials'=>['MS4_UI_WINDOW_PUBLIC_MATERIALS_PRESERVED',$materials]]as$k=>[$scope,$files])
        $out[$k]=['schema'=>1,'scope'=>$scope,'binding'=>$binding,'files'=>$files];return $out;
}


/** Finite twenty-case Source selection; syntax, bridge integration and actual UI acceptance remain separate. */
function m4uwo_case_supported(string $name):bool
{return in_array($name,m4uwo_steps(),true);}
function m4uwo_interval(array $request):array
{return [intdiv($request['started_at_ms'],1000),intdiv($request['finished_at_ms'],1000)];}
function m4uwo_b64(string $value):string
{
    m4uib_assert($value!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/D',$value)===1,'OBS_JWT_BASE64URL');
    $raw=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
    m4uib_assert(is_string($raw)&&rtrim(strtr(base64_encode($raw),'+/','-_'),'=')===$value,'OBS_JWT_CANONICAL_BASE64URL');return $raw;
}
function m4uwo_claims(string $token,string $app,int $id,array $interval,?array $session=null):array
{
    $cfg=audit_config();$parts=explode('.',$token);m4uib_assert(count($parts)===3,'OBS_NORMAL_SIGNED_TOKEN_THREE_PARTS');
    $header=json_decode(m4uwo_b64($parts[0]),true,16,JSON_THROW_ON_ERROR);m4uib_keys($header,['typ','alg']);
    m4uib_assert($header['typ']==='JWT'&&$header['alg']==='HS256'&&is_string($cfg['signing_key'])&&$cfg['signing_key']!=='', 'OBS_KNOWN_NORMAL_JOSE_ALGORITHM');
    m4uib_assert(hash_equals(hash_hmac('sha256',$parts[0].'.'.$parts[1],$cfg['signing_key'],true),m4uwo_b64($parts[2])),'OBS_NORMAL_REAL_SIGNING_KEY_MAC');
    $c=json_decode(m4uwo_b64($parts[1]),true,32,JSON_THROW_ON_ERROR);m4uib_assert(is_array($c)&&!array_is_list($c),'OBS_SIGNED_CLAIMS_OBJECT');
    m4uib_assert(is_int($c['iat']??null)&&is_int($c['nbf']??null)&&is_int($c['exp']??null)&&$c['iat']===$c['nbf']
        &&$c['iat']>=$interval[0]&&$c['iat']<=$interval[1]&&$c['exp']===$c['iat']+30*86400,'OBS_SOURCE_TTL_AND_REAL_NORMAL_ISSUE_TIME');
    m4uib_assert(is_array($c['jti']??null)&&(($app==='api'&&(is_int($c['jti']['id']??null)||is_string($c['jti']['id']??null))&&(string)$c['jti']['id']===(string)$id)||($app!=='api'&&($c['jti']['id']??null)===(string)$id)),'OBS_SIGNED_NATIVE_SUBJECT');
    if($app==='api')m4uib_assert(($c['jti']['type']??null)==='api','OBS_LEGACY_API_SIGNED_TYPE');
    else{
        $type=$app==='adminapi'?'admin':'merchant';$aud=$app==='adminapi'?'adminapi':'tianli:merchantapi';
        m4uib_assert($c['auth_schema']===2&&$c['session_epoch']===$cfg['auth']['session_epoch']&&$c['iss']===$cfg['auth']['issuer']
            &&$c['aud']===$aud&&$c['jti']['type']===$type&&is_array($session)&&$c['sid']===$session['sid']
            &&$c['session_version']===$session['session_version']&&$c['exp']===$session['exp'],'OBS_SOURCE_APPS_TYPE_AUD_TTL_ISSUER_AND_NATIVE_SESSION_BINDING');
    }
    return $c;
}
function m4uwo_redis_delta(array $before,array $after,array $allowed,array $tags=[]):void
{
    $expected=$before['native_redis'];$keys=$before['redis']['keys'];
    foreach($allowed as$key=>$entry){$h=hash('sha256',(string)$key);
        if($entry===null){m4uib_assert(!isset($after['native_redis'][$key])&&!isset($after['redis']['keys'][$h]),'OBS_FINITE_EXPECTED_REDIS_REMOVAL');unset($expected[$key],$keys[$h]);}
        else{m4uib_assert(isset($after['native_redis'][$key])&&$after['native_redis'][$key]===$entry,'OBS_ALREADY_INDEPENDENTLY_VERIFIED_REDIS_LEAF');
            $expected[$key]=$entry;$keys[$h]=$after['redis']['keys'][$h];}}
    ksort($expected,SORT_STRING);ksort($keys,SORT_STRING);
    m4uib_assert($expected===$after['native_redis']&&$keys===$after['redis']['keys'],'OBS_ALL_OTHER_REDIS_KEYS_NATIVE_DUMP_TYPE_VALUE_ABSOLUTE_EXPIRY_UNCHANGED');
    $members=$before['tag_members'];foreach($tags as$key=>$value)$members[$key]=$value;
    m4uib_assert($members===$after['tag_members'],'OBS_ALL_OTHER_COMPLETE_TAG_MEMBERS_UNCHANGED');
}
function m4uwo_login_customer(array $before,array $after,int $id,array $request,?string $observed,array &$state):array
{
    $interval=m4uwo_interval($request);$new=[];
    foreach($after['native_redis']as$key=>$r)if(!array_key_exists($key,$before['native_redis'])&&preg_match('/\Ac:[a-f0-9]{32}\z/D',(string)$key)===1&&$r['type']==='string'){
        $value=@unserialize($r['value'],['allowed_classes'=>false]);
        if(is_array($value)&&($value['type']??null)==='api'&&($value['uid']??null)===$id)$new[$key]=[$r,$value];
    }
    m4uib_assert(count($new)===1,'OBS_UNIQUE_REAL_NEW_CUSTOMER_SESSION');$key=array_key_first($new);[$r,$v]=$new[$key];m4uib_keys($v,['uid','type','token','exp']);
    $ttl=30*86400+60;m4uib_assert(is_string($v['token'])&&$key==='c:'.md5($v['token'])&&$v['exp']===$ttl
        &&$r['value']===serialize(['uid'=>$id,'type'=>'api','token'=>$v['token'],'exp'=>$ttl]),'OBS_EXACT_SOURCE_LEGACY_SESSION_NATIVE_SERIALIZATION');
    if($observed!==null)m4uib_assert(hash_equals($v['token'],$observed),'OBS_ACTUAL_UI_RESPONSE_TOKEN_EQUALS_NATIVE_SESSION');
    $c=m4uwo_claims($v['token'],'api',$id,$interval);
    m4uib_assert($r['expires']>=$interval[0]*1000+$ttl*1000&&$r['expires']<=($interval[1]+1)*1000+$ttl*1000,'OBS_REAL_NATIVE_LEGACY_ABSOLUTE_SESSION_DEADLINE');
    $tag='c:CRMEB:'.md5('api');$members=$before['tag_members'][$tag];$members[]=$key;$members=array_values(array_unique($members,SORT_STRING));sort($members,SORT_STRING);
    $t=$after['native_redis'][$tag]??null;m4uib_assert(is_array($t)&&$t['type']==='set'&&$t['expires']===-1&&$t['value']===$members
        &&$after['tag_members'][$tag]===$members,'OBS_EXACT_CUSTOMER_TAG_UNION_AND_PERMANENT_DEADLINE');
    $state['login_native_plan']=['redis'=>[$key=>$r],'tags'=>[$tag=>$members]];
    $state['sessions']['customer']=['app'=>'api','id'=>$id,'token'=>$v['token'],'key'=>$key,'claims'=>$c,'signed_claims_verified'=>true];
    return ['application'=>'api','subject_id'=>$id,'native_session_verified'=>true,'signed_claims_verified'=>true,'browser_token_join'=>$observed!==null];
}
function m4uwo_login_v2(array $before,array $after,string $app,string $role,int $id,array $request,?string $observed,array &$state):array
{
    $cfg=audit_config();$auth=$cfg['auth'];
    m4uib_assert(is_array($auth)&&is_string($auth['session_epoch']??null)&&preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D',$auth['session_epoch'])===1
        &&is_string($auth['issuer']??null)&&preg_match('~\A[A-Za-z0-9._:/-]{1,128}\z~D',$auth['issuer'])===1
        &&is_string($auth['stamp_key']??null)&&strlen($auth['stamp_key'])>=32&&$auth['stamp_key']!==$cfg['signing_key'],'OBS_ACTUAL_PRIVATE_AUTH_CONFIG_IN_RAM');
    $prefix='auth:v2:'.$auth['session_epoch'].':'.$app.':';$account=$prefix.'account:'.$id;
    $a=$before['native_redis'][$account]??null;m4uib_assert(is_array($a)&&$a['type']==='hash'&&$a['expires']===-1,'OBS_ACTUAL_PREPARED_ACCOUNT_RECORD');
    $av=$a['value'];m4uib_keys($av,['blocked','initialized','session_version']);
    m4uib_assert($av['blocked']==='0'&&$av['initialized']==='1'&&m4uib_hex($av['session_version'],32)
        &&($after['native_redis'][$account]??null)===$a,'OBS_ORIGINAL_ACCOUNT_VERSION_NOT_REGENERATED_OR_MUTATED');
    $found=[];foreach($after['native_redis']as$key=>$r)if(str_starts_with((string)$key,$prefix.'session:')&&!array_key_exists($key,$before['native_redis']))$found[$key]=$r;
    m4uib_assert(count($found)===1,'OBS_ONE_NEW_REAL_V2_SESSION');$key=array_key_first($found);$r=$found[$key];$sid=substr($key,strlen($prefix.'session:'));
    m4uib_assert(m4uib_hex($sid,32)&&$r['type']==='hash'&&is_array($r['value']),'OBS_NEW_V2_NORMAL_RANDOM_SID_NATIVE_HASH');$v=$r['value'];
    m4uib_keys($v,['subject_id','type','audience','session_version','token_digest','credential_stamp','expires_at','status']);
    $type=$app==='adminapi'?'admin':'merchant';$aud=$app==='adminapi'?'adminapi':'tianli:merchantapi';
    $table=$app==='adminapi'?'eb_system_admin':'eb_marketplace_employee';$owner=m4ar_one($before['rows'][$table],'id',$id);$interval=m4uwo_interval($request);
    m4uib_assert(is_string($owner['pwd'])&&$owner['pwd']!==''&&$v['subject_id']===(string)$id&&$v['type']===$type&&$v['audience']===$aud
        &&$v['session_version']===$av['session_version']&&m4uib_hex($v['token_digest'])&&$v['status']==='active'
        &&$v['credential_stamp']===hash_hmac('sha256',$app.'|'.$id.'|'.$owner['pwd'],$auth['stamp_key'])
        &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$v['expires_at'])===1,'OBS_SOURCE_APP_TYPE_AUDIENCE_ID_VERSION_CREDENTIAL_STAMP');
    $exp=(int)$v['expires_at'];m4uib_assert($exp>=$interval[0]+30*86400&&$exp<=$interval[1]+30*86400&&abs($r['expires']-($exp+60)*1000)<=1,'OBS_SOURCE_APP_TTL_AND_NATIVE_ABSOLUTE_EXPIRY');
    $session=['sid'=>$sid,'session_version'=>$av['session_version'],'exp'=>$exp];$claims=null;
    if($observed!==null){m4uib_assert(hash_equals($v['token_digest'],hash('sha256',$observed)),'OBS_UI_OBSERVED_NORMAL_TOKEN_REAL_SESSION_DIGEST');
        $claims=m4uwo_claims($observed,$app,$id,$interval,$session);}
    $state['login_native_plan']=['redis'=>[$key=>$r],'tags'=>[]];
    // Without a supported normal browser response token, issuer/JWT claims stay explicitly unverified.
    $state['sessions'][$role]=['app'=>$app,'id'=>$id,'token'=>$observed,'key'=>$key,'claims'=>$claims,'signed_claims_verified'=>$claims!==null];
    return ['application'=>$app,'subject_id'=>$id,'native_session_verified'=>true,'signed_claims_verified'=>$claims!==null,'browser_token_join'=>$observed!==null];
}
function m4uwo_customer_touch(array $before,array $after,int $id,array $event):array
{
    $old=m4ar_one($before['rows']['eb_user'],'uid',$id);$row=m4ar_one($after['rows']['eb_user'],'uid',$id);$want=$old;$want['last_time']=$row['last_time'];
    m4ar_time(intdiv($event['started_at_ms'],1000),intdiv($event['finished_at_ms'],1000),$want['last_time']);
    m4uib_assert($row===$want,'OBS_CUSTOMER_AUTH_ONLY_BOUNDED_LAST_TIME');return ['eb_user'=>m4ar_replaced($before['rows']['eb_user'],'uid',$id,$want)];
}
function m4uwo_case_requests(array $event,array $allowed,string $role,int $id):void
{
    foreach($event['requests']as$r){m4uib_assert(in_array($r['method'].' '.$r['path'],$allowed,true)&&$r['app_status']===200,'OBS_ONLY_EXPLICIT_NORMAL_ASSOCIATED_REQUESTS');
        $a=$r['authentication'];m4uib_assert($a['present']===true&&$a['kind']==='Bearer'&&$a['subject_id']===$id,'OBS_ACTUAL_AUTHENTICATED_NORMAL_REQUEST_IDENTITY');}
    $identity=m4uwo_plain($event['identity']);m4uib_keys($identity,['application','subject_id','merchant_id','verified_by']);
    $app=match($role){'customer'=>'api','merchant'=>'merchantapi','platform'=>'adminapi'};
    m4uib_assert($identity['application']===$app&&$identity['subject_id']===$id&&$identity['verified_by']==='native-session-and-normal-UI','OBS_SAFE_BROWSER_IDENTITY_JOINS_NATIVE_ROLE');
}
function m4uwo_file_delta(array $before,array $after,array $allowedRoots=[]):void
{
    $a=$before['files'];$z=$after['files'];unset($a['/workspace/crmeb/runtime'],$z['/workspace/crmeb/runtime']);
    foreach($allowedRoots as$root){unset($a[$root],$z[$root]);}
    m4uib_assert($a===$z,'OBS_ALL_OTHER_COMPLETE_FILE_ROOTS_PRESERVED');
}
function m4uwo_private_file_delta(array $before,array $after,string $relative,array $descriptor,array $interval):void
{
    $root=M4AR_PRIVATE;$a=$before['files'][$root];$z=$after['files'][$root];m4uib_assert(is_array($a)&&is_array($z)&&!isset($a[$relative]),'OBS_PRIVATE_OBJECT_FRESH_LEAF');
    $newDirs=[];$parts=explode('/',$relative);array_pop($parts);$parent='';
    foreach($parts as$part){$parent=$parent===''?$part:$parent.'/'.$part;if(!isset($a[$parent]))$newDirs[]=$parent;else m4uib_assert($a[$parent]['kind']==='directory','OBS_PRIVATE_EXISTING_ORDINARY_PARENT');}
    $names=array_merge(array_keys($a),$newDirs,[$relative]);sort($names,SORT_STRING);$actual=array_keys($z);sort($actual,SORT_STRING);
    m4uib_assert($actual===$names,'OBS_EXACT_PRIVATE_TREE_UNION_NO_EXTRA_EMPTY_DIRECTORY_OR_FILE');$inos=[];foreach($a as$r)$inos[$r['dev'].':'.$r['ino']]=true;
    foreach($a as$name=>$r){$want=$r;if($r['kind']==='directory')foreach($newDirs as$d)if(dirname($d)===$name||($name===''&&!str_contains($d,'/')))++$want['nlink'];
        m4uib_assert($z[$name]===$want,'OBS_ALL_OLD_PRIVATE_BYTES_METADATA_AND_EXACT_DIRECTORY_LINK_DELTA');}
    foreach($newDirs as$d){$r=$z[$d];$children=0;foreach($newDirs as$x)if(dirname($x)===$d)++$children;
        m4uib_assert($r['kind']==='directory'&&$r['dev']===$a['']['dev']&&$r['ino']>0&&!isset($inos[$r['dev'].':'.$r['ino']])
            &&$r['mode']===0700&&$r['uid']===0&&$r['gid']===0&&$r['nlink']===2+$children,'OBS_NEW_PRIVATE_DIRECTORY_EXACT_NATIVE_MODE_OWNER_LINKS');$inos[$r['dev'].':'.$r['ino']]=true;}
    $r=$z[$relative];m4uib_assert($r['kind']==='file'&&$r['dev']===$a['']['dev']&&$r['ino']>0&&!isset($inos[$r['dev'].':'.$r['ino']])
        &&$r['mode']===0600&&$r['uid']===0&&$r['gid']===0&&$r['nlink']===1&&$r['bytes']===$descriptor['bytes']&&$r['sha256']===$descriptor['sha256']
        &&$r['mtime']>=$interval[0]&&$r['mtime']<=$interval[1]&&$r['ctime']>=$interval[0]&&$r['ctime']<=$interval[1],'OBS_ONE_PRIVATE_OBJECT_EXACT_FULL_BYTES_NATIVE_IDENTITY');
    $native=m4uib_file($root.'/'.$relative,1048576,true);$frozen=m4uib_file('/workspace/crmeb/public/__ms4_fixture__/product.png',1048576,true);m4uib_assert($native['raw']===$frozen['raw'],'OBS_NEW_IMAGE_COMPLETE_NATIVE_BYTES_EQUAL_FROZEN_IMAGE');unset($native,$frozen);
}

function m4uwo_one_file_delta(array $before,array $after,string $root,string $relative,array $descriptor,array $interval,int $fileMode,int $directoryMode):void
{
    $a=$before['files'][$root];$z=$after['files'][$root];m4uib_assert(is_array($a)&&is_array($z)&&!isset($a[$relative]),'OBS_PRIVATE_OBJECT_FRESH_LEAF');$expectedDevice=$a['']['dev'];
    $newDirs=[];$parts=explode('/',$relative);array_pop($parts);$parent='';
    foreach($parts as$part){$parent=$parent===''?$part:$parent.'/'.$part;if(!isset($a[$parent]))$newDirs[]=$parent;else{m4uib_assert($a[$parent]['kind']==='directory','OBS_PRIVATE_EXISTING_ORDINARY_PARENT');$expectedDevice=$a[$parent]['dev'];}}
    $names=array_merge(array_keys($a),$newDirs,[$relative]);sort($names,SORT_STRING);$actual=array_keys($z);sort($actual,SORT_STRING);
    m4uib_assert($actual===$names,'OBS_EXACT_PRIVATE_TREE_UNION_NO_EXTRA_EMPTY_DIRECTORY_OR_FILE');$inos=[];foreach($a as$r)$inos[$r['dev'].':'.$r['ino']]=true;
    foreach($a as$name=>$r){$want=$r;if($r['kind']==='directory')foreach($newDirs as$d)if(dirname($d)===$name||($name===''&&!str_contains($d,'/')))++$want['nlink'];
        m4uib_assert($z[$name]===$want,'OBS_ALL_OLD_PRIVATE_BYTES_METADATA_AND_EXACT_DIRECTORY_LINK_DELTA');}
    foreach($newDirs as$d){$r=$z[$d];$children=0;foreach($newDirs as$x)if(dirname($x)===$d)++$children;
        m4uib_assert($r['kind']==='directory'&&$r['dev']===$expectedDevice&&$r['ino']>0&&!isset($inos[$r['dev'].':'.$r['ino']])
            &&$r['mode']===$directoryMode&&$r['uid']===0&&$r['gid']===0&&$r['nlink']===2+$children,'OBS_NEW_PRIVATE_DIRECTORY_EXACT_NATIVE_MODE_OWNER_LINKS');$inos[$r['dev'].':'.$r['ino']]=true;}
    $r=$z[$relative];m4uib_assert($r['kind']==='file'&&$r['dev']===$expectedDevice&&$r['ino']>0&&!isset($inos[$r['dev'].':'.$r['ino']])
        &&$r['mode']===$fileMode&&$r['uid']===0&&$r['gid']===0&&$r['nlink']===1&&$r['bytes']===$descriptor['bytes']&&$r['sha256']===$descriptor['sha256']
        &&$r['mtime']>=$interval[0]&&$r['mtime']<=$interval[1]&&$r['ctime']>=$interval[0]&&$r['ctime']<=$interval[1],'OBS_ONE_PRIVATE_OBJECT_EXACT_FULL_BYTES_NATIVE_IDENTITY');
    $native=m4uib_file($root.'/'.$relative,1048576,true);$frozen=m4uib_file('/workspace/crmeb/public/__ms4_fixture__/product.png',1048576,true);m4uib_assert($native['raw']===$frozen['raw'],'OBS_NEW_IMAGE_COMPLETE_NATIVE_BYTES_EQUAL_FROZEN_IMAGE');unset($native,$frozen);
}

/** Independent expected models derived from finite original text. Authoring did not parse or execute PHP. */
function m4uwo_model_start(array $state,array $baseline):array
{
 $root=$state['saved']['order'];$paid=[];foreach($baseline['rows']['eb_marketplace_payment_attempt']as$r)
  if((int)$r['order_id']===(int)$root['id']&&$r['applied_trade_no']===$root['trade_no'])$paid[]=$r;
 m4uib_assert(count($paid)===1&&is_string($root['trade_no'])&&$root['trade_no']!==''&&(int)$root['paid']===1&&(int)$root['status']===0,'OBS_UNIQUE_ACTUAL_PREPARED_APPLIED_PAYMENT');
 return ['source'=>$state['source'],'root'=>$root,'payment_attempt_id'=>m5pcp_u64($paid[0]['id']),'refunds'=>[],'last_action'=>null,'last_operation'=>null,
  'cancel'=>[],'shipped'=>[],'received'=>[],'returned'=>[],'child'=>null,'children'=>[],'ship_operations'=>[],'ship_ranges'=>[],'actions'=>[],'operations'=>[],'command_counts'=>[],'replays'=>0];
}
function m4uwo_summary(array $model):array
{$s=m4ar_summary($model);$s['child_count']=count($model['children']);return $s;}
function m4uwo_audit_delta(array $before,array $after,array $actor,array $spec,array $interval,array &$expected,array &$counts,array $source):void
{
 $table=($spec['global']??false)?'eb_system_log':'eb_marketplace_audit_log';$new=m4ar_new_rows($before['rows'][$table],$after['rows'][$table]);
 m4uib_assert(count($new)===1,'OBS_ONE_INDEPENDENT_EXPECTED_AUDIT');$row=$new[0];$at=m4upo_time($row['add_time'],$interval);$pk=m4upo_next($before,$table);
 if($table==='eb_system_log'){$rule=$spec['rule'];$found=[];foreach($before['rows']['eb_system_menus']as$r)if($r['api_url']===$rule)$found[]=$r;
  m4uib_assert(count($found)<=1,'OBS_UNAMBIGUOUS_ORIGINAL_VISIT_NAME');$values=['id'=>$pk,'method'=>'adminapi','admin_id'=>$actor['id'],
   'add_time'=>$at,'admin_name'=>$actor['account'],'path'=>$rule,'page'=>$found===[]?'未知':($found[0]['menu_name']?:'未知'),'ip'=>m4uwo_gateway_ip($source),'type'=>'system'];
 }else{$values=['id'=>$pk,'mer_id'=>$spec['mer_id'],'actor_type'=>$actor['app']==='merchantapi'?'merchant':'admin','actor_id'=>$actor['id'],
  'action'=>$spec['action'],'resource_type'=>$spec['resource_type'],'resource_id'=>(string)$spec['resource_id'],'result'=>'success','detail'=>$row['detail'],'add_time'=>$at];}
 $detail=json_decode($row['detail']??'{}',false,32,JSON_THROW_ON_ERROR);if($table!=='eb_system_log')m4uib_assert($detail instanceof stdClass&&m4uib_same(get_object_vars($detail),$spec['detail']??[]),'OBS_COMPLETE_INDEPENDENT_AUDIT_DETAIL_OBJECT');
 $want=m4upo_row($before,$row,$table,$values);$expected[$table]=array_merge($before['rows'][$table],[$want]);$counts[$table]=1;
}
function m4uwo_sequence(array $before,array $after,string $number,array $interval):array
{
 $n=preg_replace('/\A(?:cp|cf)/','',$number);m4uib_assert(is_string($n)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$n)===1,'OBS_REAL_NORMAL_SNOWFLAKE_NUMBER');
 $ms=bcadd('1640966400000',bcdiv($n,'4194304',0),0);m4uib_assert(bccomp($ms,(string)($interval[0]*1000),0)>=0&&bccomp($ms,(string)(($interval[1]+1)*1000),0)<0,'OBS_REAL_NEW_SEQUENCE_TIME_INSIDE_NORMAL_REQUEST');
 $r=$after['native_redis'][$ms]??null;m4uib_assert(!array_key_exists($ms,$before['native_redis']),'OBS_NEW_NORMAL_SEQUENCE_KEY_ONLY');
 if($r===null){m4uib_assert(bccomp($after['redis']['time_ms'],bcadd($ms,'1000',0),0)>=0,'OBS_SOURCE_ONE_SECOND_COUNTER_ALREADY_NATURALLY_EXPIRED');return [];}
 $seq=bcmod($n,'4096',0);m4uib_assert($r['type']==='string'&&is_string($r['value'])&&preg_match('/\A[1-9][0-9]*\z/D',$r['value'])===1
  &&($seq==='0'?$r['value']==='1':$r['value']===$seq)&&$r['expires']>=(int)$ms+1000
  &&bccomp((string)$r['expires'],bcadd($after['redis']['time_ms'],'1000',0),0)<=0,'OBS_NATIVE_SOURCE_SEQUENCE_EXACT_VALUE_AND_BOUNDED_ORIGINAL_DEADLINE');return [$ms=>$r];
}
function m4uwo_refund_model(array $before,array $after,array &$model,array $actor,string $action,array $body,array $answer,array $interval,array $observerSource,?int $refundId=null,array $accepted=[]):array
{
 m4uib_assert(in_array($action,['apply','approve'],true),'OBS_ONLY_N1_APPLY_APPROVE');$reply=['json'=>['data'=>$answer],'actual_interval'=>$interval];
 m4c_entry_keys($answer,['schema','scope','action_id','action','refund','committed_at','acceptance_ids']);
 $new=m4ar_new_rows($before['rows']['eb_marketplace_aftersale_action'],$after['rows']['eb_marketplace_aftersale_action']);
 m4ar_expect(count($new)===1,'TASK78_ONE_REAL_NEW_COMMITTED_ACTION');$native=$new[0];$actionId=m5pcp_u64($native['id']);
 m4ar_expect($actionId===$answer['action_id']&&$actionId===m5pcp_u64($before['database']['tables']['eb_marketplace_aftersale_action']['metadata']['table']['AUTO_INCREMENT']),
 'TASK78_ACTUAL_ACTION_PK_FROM_NATIVE_INSERT');$at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$native['created_at']);
 $committed=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$native['committed_at']);m4ar_expect($committed>=$at,'TASK78_COMMIT_NOT_BEFORE_ACTION');
 if($action==='apply'){$refundId=(int)$native['refund_id'];$refund=m4ar_one($after['rows']['eb_store_order_refund'],'id',$refundId);
  m4ar_expect((string)$refundId===m5pcp_u64($before['database']['tables']['eb_store_order_refund']['metadata']['table']['AUTO_INCREMENT']),
  'TASK78_REFUND_NATURAL_ID');$record=['id'=>$refundId,'number'=>$refund['order_id'],'body'=>$body,'kind'=>$body['operation_kind'],
   'allocations'=>m4ar_apply_allocations($model,$body),'accepted'=>[],'created_at'=>$at,'apply_action'=>$actionId,'last_action'=>null,'state'=>null];
 }else{m4ar_expect($refundId!==null&&isset($model['refunds'][$refundId]),'TASK78_EXISTING_INDEPENDENT_REFUND_HISTORY');$record=$model['refunds'][$refundId];
  $refund=m4ar_one($after['rows']['eb_store_order_refund'],'id',$refundId);}
 $previous=$record['state'];$record['accepted']=array_merge($record['accepted'],$accepted);
 $state=m4ar_refund_state($previous,$action,$body,$record['kind'],m4ar_quantity($record['accepted']),m4ar_quantity($record['allocations']));
 $physical=$action==='approve'&&$record['kind']==='cancel_unshipped'?$record['allocations']:($action==='accept_return'?$accepted:[]);
 $rows=[];$counts=[];
 $movementRefs=m4ar_credit_oracle($before,$after,$model,$actor,$refundId,$actionId,$action==='approve'?'release':'return_in',$physical,$at,$rows,$counts);
 if($action==='approve'&&$record['kind']==='cancel_unshipped')$model['cancel']=array_merge($model['cancel'],$physical);
 if($action==='accept_return')$model['returned']=array_merge($model['returned'],$physical);
 $view=m4ar_refund_view($model,$record,$state,$actor,$at);$acceptance=$action==='accept_return'?array_values(array_unique(array_column($movementRefs,'movement_id'))):[];
 $public=['schema'=>2,'scope'=>'marketplace_aftersale_action_v1','action_id'=>$actionId,'action'=>$action,'refund'=>$view,'committed_at'=>$committed,'acceptance_ids'=>$acceptance];
 m4c_entry_same($answer,$public);$internal=['schema'=>1,'before'=>$previous,'after'=>$state,'allocations'=>$record['allocations'],
 'movements'=>$movementRefs,'public_result'=>$public];
 $type=match($actor['app']){'api'=>'customer','merchantapi'=>'employee','adminapi'=>'admin'};$actorRef=$type.':'.$actor['id'];
 $request=['schema'=>1,'action'=>$action,'root_order_id'=>(int)$model['root']['id'],'refund_id'=>$refundId,'public_body'=>$body];
 $values=['id'=>$native['id'],'contract_version'=>1,'root_order_id'=>(int)$model['root']['id'],'uid'=>(int)$model['root']['uid'],
 'mer_id'=>(int)$model['root']['mer_id'],'refund_id'=>$refundId,'payment_attempt_id'=>$model['payment_attempt_id'],'action'=>$action,
 'actor_type'=>$type,'actor_id'=>$actor['id'],'actor_ref'=>$actorRef,'idem_key'=>$body['idem_key'],
 'request_digest'=>hash('sha256',m5pcp_canonical(['actor_ref'=>$actorRef,'request_payload'=>$request]),true),
 'request_payload'=>['json'=>$request],'result_payload'=>['json'=>$internal],'state'=>1,'previous_refund_action_id'=>$record['last_action'],
 'fulfillment_cut_id'=>$model['last_operation'],'apply_refund_id'=>$action==='apply'?$refundId:null,'created_at'=>$at,'committed_at'=>$committed];
 m4fn_expected_row($before['database']['tables']['eb_marketplace_aftersale_action']['metadata'],$native,$values);
 $rows['eb_marketplace_aftersale_action']=array_merge($before['rows']['eb_marketplace_aftersale_action'],[$native]);$counts['eb_marketplace_aftersale_action']=1;
 if($action==='apply'){$items=m4ar_public_items($model,$record['allocations'],[]);$cart=[];foreach($items as$i)$cart[]=$i['item'];
  $refundValues=['id'=>$refundId,'store_order_id'=>(int)$model['root']['id'],'store_id'=>0,'order_id'=>$record['number'],'uid'=>(int)$model['root']['uid'],
   'refund_type'=>$state['refund_type'],'refund_num'=>m4ar_quantity($record['allocations']),'refund_price'=>$body['amount'],'refunded_price'=>'0.00',
   'refund_phone'=>'','refund_express'=>'','refund_express_name'=>'','refund_explain'=>'','refund_img'=>m5pcp_canonical($body['images']),
   'refund_reason'=>$body['reason'],'refuse_reason'=>'','remark'=>'','refunded_time'=>0,'cart_info'=>m5pcp_canonical($cart),'is_cancel'=>0,
   'is_pink_cancel'=>0,'is_del'=>0,'add_time'=>$at,'is_system_del'=>0,'mer_id'=>(int)$model['root']['mer_id'],
   'marketplace_refund_mode'=>$record['kind']==='return_goods'?'return_goods':'money_only','marketplace_root_order_id'=>(int)$model['root']['id'],
   'marketplace_payment_attempt_id'=>$model['payment_attempt_id'],'marketplace_kind'=>$record['kind'],'marketplace_state'=>$state['business_state'],
   'marketplace_physical_state'=>$state['physical_state'],'marketplace_finance_state'=>$state['finance_state'],
   'marketplace_apply_action_id'=>$actionId,'marketplace_last_action_id'=>$actionId];
  m4fn_expected_row($before['database']['tables']['eb_store_order_refund']['metadata'],$refund,$refundValues);
  $rows['eb_store_order_refund']=array_merge($before['rows']['eb_store_order_refund'],[$refund]);$counts['eb_store_order_refund']=1;
 }else{$old=m4ar_one($before['rows']['eb_store_order_refund'],'id',$refundId);$want=$old;$delivery=$state['return_delivery'];
  $changes=['refund_type'=>$state['refund_type'],'refunded_price'=>'0.00','refund_express'=>$delivery===null?'':$delivery['tracking_no'],
   'refund_express_name'=>$delivery===null?'':$delivery['carrier_name'],'refunded_time'=>$at,'is_cancel'=>$state['is_cancel'],
   'marketplace_root_order_id'=>(int)$model['root']['id'],'marketplace_payment_attempt_id'=>$model['payment_attempt_id'],
   'marketplace_kind'=>$record['kind'],'marketplace_state'=>$state['business_state'],'marketplace_physical_state'=>$state['physical_state'],
   'marketplace_finance_state'=>$state['finance_state'],'marketplace_apply_action_id'=>$record['apply_action'],'marketplace_last_action_id'=>$actionId];
  if($action==='reject')$changes['refuse_reason']=$body['reason'];foreach($changes as$k=>$v)$want[$k]=m4fn_native_value($before['database']['tables']['eb_store_order_refund']['metadata']['columns'][$k],$v);
  m4ar_expect($want===$refund,'TASK78_FULL_NATIVE_REFUND_MIRROR_ONLY_INDEPENDENT_STATE');$rows['eb_store_order_refund']=m4ar_replaced($before['rows']['eb_store_order_refund'],'id',$refundId,$want);
 }
 if($action==='apply'&&$body['images']!==[]){$files=$before['rows']['eb_marketplace_aftersale_file'];foreach($body['images']as$position=>$id){
   $old=m4ar_one($files,'id',$id);$now=m4ar_one($after['rows']['eb_marketplace_aftersale_file'],'id',$id);$want=$old;$want['refund_id']=$refundId;$want['image_position']=$position;
   $want['claimed_at']=$now['claimed_at'];$want['state']='CLAIMED';m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$now['claimed_at']);
   m4ar_expect($now===$want,'TASK78_REAL_FILE_CLAIM_ONLY_FOUR_NATIVE_COLUMNS');$files=m4ar_replaced($files,'id',$id,$want);}$rows['eb_marketplace_aftersale_file']=$files;}
 $summary=m4ar_summary($model);$newStatus=$summary['to_ship']>0?0:($summary['to_receive']>0?1:($summary['shipped']>0?2:0));$orderRows=$before['rows']['eb_store_order'];
 $root=m4ar_one($orderRows,'id',$model['root']['id']);$wanted=$root;$wanted['status']=$newStatus;$orderRows=m4ar_replaced($orderRows,'id',$root['id'],$wanted);
 if($model['child']!==null){$child=m4ar_one($orderRows,'id',$model['child']['id']);$wantChild=$child;$wantChild['status']=$summary['to_receive']===0?2:1;
  $orderRows=m4ar_replaced($orderRows,'id',$child['id'],$wantChild);$model['child']=$wantChild;}
 $rows['eb_store_order']=$orderRows;
 if((int)$root['status']!==$newStatus){$logs=m4ar_new_rows($before['rows']['eb_store_order_status'],$after['rows']['eb_store_order_status']);m4ar_expect(count($logs)===1,'TASK78_ONE_ACTUAL_AFTERSALE_STATUS_LOG');
  m4fn_expected_row($before['database']['tables']['eb_store_order_status']['metadata'],$logs[0],['oid'=>(int)$root['id'],'change_type'=>'marketplace_aftersale',
   'change_message'=>'多商户售后状态更新','change_time'=>$at]);$rows['eb_store_order_status']=array_merge($before['rows']['eb_store_order_status'],$logs);$counts['eb_store_order_status']=1;}
 if($actor['app']!=='api'){$old=$action==='apply'?null:m4ar_one($before['rows']['eb_store_order_refund'],'id',$refundId);$changed=[];
  if($old!==null)foreach($old as$k=>$v)if($refund[$k]!==$v)$changed[]=$k;
  m4uwo_audit_delta($before,$after,$actor,['mer_id'=>(int)$root['mer_id'],'action'=>$actor['app']==='merchantapi'?'aftersale.'.$action:
   match($action){'approve'=>'marketplace-aftersales-approve','reject'=>'marketplace-aftersales-reject','accept_return'=>'marketplace-aftersales-return-accept'},
   'resource_type'=>'aftersale','resource_id'=>$refundId,'detail'=>['changed_fields'=>$changed]],$reply['actual_interval'],$rows,$counts,$observerSource);}
 $redis=[];if($action==='apply')$redis+=m4uwo_sequence($before,$after,$record['number'],$interval);
 $record['state']=$state;$record['last_action']=$actionId;$record['updated_at']=$at;$record['native']=$refund;$model['refunds'][$refundId]=$record;
 $model['last_action']=$actionId;$model['actions'][]=$native;$model['root']=$wanted;$model['command_counts'][$action]=($model['command_counts'][$action]??0)+1;
 return ['rows'=>$rows,'counts'=>$counts,'redis'=>$redis,'record'=>$record];
}

function m4uwo_operation_model(array $before,array $after,array &$model,array $actor,string $action,array $body,array $parts,array $answer,array $interval,int $targetChild=0):array
{
 $reply=['json'=>['data'=>$answer],'actual_interval'=>$interval];$ship=$action==='ship';
 $ops='eb_marketplace_fulfillment_operation';$ranges='eb_marketplace_fulfillment_range';$orders='eb_store_order';$items='eb_store_order_cart_info';
 $added=m4ar_new_rows($before['rows'][$ops],$after['rows'][$ops]);m4ar_expect(count($added)===1,'TASK78_ONE_REAL_S2_OPERATION');$raw=$added[0];$id=m5pcp_u64($raw['id']);
 m4ar_expect($id===m5pcp_u64($before['database']['tables'][$ops]['metadata']['table']['AUTO_INCREMENT']),'TASK78_S2_NATURAL_OPERATION_PK');
 $at=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$raw['created_at']);$commit=m4ar_time($reply['actual_interval'][0],$reply['actual_interval'][1],$raw['committed_at']);
 m4ar_expect($commit>=$at,'TASK78_S2_NATIVE_COMMIT_INTERVAL');$plan=m4ar_plan($model,$parts);$rows=[];$counts=[];
 $request=['schema'=>1,'action'=>$action,'root_order_id'=>(int)$model['root']['id']];$newChildren=[];$newItems=[];$target=null;
 if($ship){$newChildren=m4uwo_added_ids($before['rows'][$orders],$after['rows'][$orders],'id');m4ar_expect(count($newChildren)===1,'TASK78_ONE_TRUE_TWO_ITEM_CHILD');$child=$newChildren[0];m4uib_assert((int)$child['id']===m4upo_next($before,$orders),'OBS_NEW_CHILD_NATURAL_PK');
  $request['quantities']=[];foreach($plan['allocations']as$a)$request['quantities'][]=['source_item_id'=>$a['source_item_id'],'quantity'=>$a['quantity']];$request['delivery']=$body['delivery'];
  $values=m4fn_child_values($model['source'],$plan,$request,$child,$at);$values['cart_id']=['json'=>array_column($plan['allocations'],'cart_ref')];
  m4fn_expected_row($before['database']['tables'][$orders]['metadata'],$child,$values);
  $newItems=m4ar_new_rows($before['rows'][$items],$after['rows'][$items]);foreach($newItems as$i=>$newItem)m4uib_assert((int)$newItem['id']===m4upo_next($before,$items,$i),'OBS_CHILD_ITEMS_CONTIGUOUS_NATURAL_PK');m4ar_expect(count($newItems)===count($plan['allocations']),'OBS_ONE_TRUE_CHILD_ITEM_PER_ORIGINAL_ALLOCATION');
  foreach($plan['allocations']as$a){$found=array_values(array_filter($newItems,static fn(array $r):bool=>(int)$r['product_id']===$a['product_id']));
   m4ar_expect(count($found)===1,'TASK78_CHILD_ITEM_ORIGINAL_SOURCE');m4fn_expected_row($before['database']['tables'][$items]['metadata'],$found[0],m4fn_item_values($model['source'],$a,$child,$id,$found[0]));}
  $model['child']=$child;$model['children'][(int)$child['id']]=$child;$counts[$orders]=1;$counts[$items]=count($plan['allocations']);$rows[$items]=array_merge($before['rows'][$items],$newItems);
 }else{$child=$model['children'][$targetChild]??null;$model['child']=$child;m4ar_expect(is_array($child),'TASK78_REAL_EXISTING_CHILD_FOR_RECEIVE');$request['child_order_id']=(int)$child['id'];
  $request['selection']=['mode'=>'ranges','ranges'=>[]];foreach($parts as$p)$request['selection']['ranges'][]=['source_item_id'=>$p['source_item_id'],'unit_start'=>$p['unit_start'],'unit_end'=>$p['unit_end']];
  $target=$model['ship_operations'][$targetChild];}
 $childId=(int)$child['id'];$newRanges=m4ar_new_rows($before['rows'][$ranges],$after['rows'][$ranges]);m4ar_expect(count($newRanges)===count($parts),'TASK78_S2_EXACT_ACTUAL_RANGES');
 $refs=[];$digestParts=[];foreach($plan['allocations']as$i=>$a){$found=array_values(array_filter($newRanges,static fn(array $r):bool=>(int)$r['source_item_id']===$a['source_item_id']));
  m4ar_expect(count($found)===1,'TASK78_ONE_TRUE_RANGE_PER_SELECTED_ITEM');$range=$found[0];m4uib_assert((int)$range['id']===m4upo_next($before,$ranges,$i),'OBS_RANGE_CONTIGUOUS_NATURAL_PK');$parent=null;
  if(!$ship){$parents=array_values(array_filter($model['ship_ranges'],static fn(array $r):bool=>(int)$r['source_item_id']===$a['source_item_id']));m4ar_expect(count($parents)===1,'TASK78_RECEIVE_ORIGINAL_SHIP_PARENT');$parent=(string)$parents[0]['id'];}
  $childItem=m4ar_one($after['rows'][$items],'id',$range['child_item_id']);m4ar_expect((int)$childItem['oid']===$childId&&(int)$childItem['product_id']===$a['product_id'],'TASK78_RANGE_TRUE_CHILD_ITEM');
  $values=['id'=>$range['id'],'operation_id'=>$id,'operation_action'=>$action,'root_order_id'=>(int)$model['root']['id'],'child_order_id'=>$childId,
   'uid'=>(int)$model['root']['uid'],'mer_id'=>(int)$model['root']['mer_id'],'source_item_id'=>$a['source_item_id'],'child_item_id'=>(int)$childItem['id'],
   'reserve_id'=>$a['reserve_id'],'source_ship_range_id'=>$parent,'source_range_action'=>$ship?null:'ship'];
  foreach(['product_id','sku_id','sku_type','sku_unique','cart_ref','original_quantity','unit_start','unit_end','quantity','unit_price','price_type',
   'goods','postage','origin_postage','payable','cost','base_goods','discount']as$k)$values[$k]=$a[$k];
  m4fn_expected_row($before['database']['tables'][$ranges]['metadata'],$range,$values);$digestParts[]=$a+['source_ship_range_id'=>$parent];
  $refs[]=['range_id'=>(string)$range['id'],'source_item_id'=>$a['source_item_id'],'child_item_id'=>(int)$childItem['id'],'source_ship_range_id'=>$parent,
   'unit_start'=>$a['unit_start'],'unit_end'=>$a['unit_end']];}
 if($ship){$model['shipped']=array_merge($model['shipped'],$parts);$model['ship_ranges']=array_merge($model['ship_ranges'],$newRanges);$model['ship_operation']=$id;$model['ship_operations'][$childId]=$id;}
 else $model['received']=array_merge($model['received'],$parts);$summary=m4uwo_summary($model);if($summary['closed'])$model['completed_at']=$at;$aftersale=m4ar_aftersale($model);
 $public=['schema'=>2,'operation_id'=>$id,'action'=>$action,'root_order_id'=>(int)$model['root']['id'],'root_order_no'=>$model['root']['order_id'],
 'child_order_id'=>$childId,'child_order_no'=>$child['order_id'],'quantity'=>$plan['totals']['quantity'],'created_at'=>$at,'committed_at'=>$commit,
 'shipped_at'=>$ship?$at:null,'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$at,'fulfillment_summary'=>$summary,'aftersale'=>$aftersale];
 m4c_entry_same($reply['json']['data'],$public);
 $internal=['schema'=>2,'operation_id'=>$id,'action'=>$action,'root_order_id'=>(int)$model['root']['id'],'root_order_no'=>$model['root']['order_id'],
 'child_order_id'=>$childId,'child_order_no'=>$child['order_id'],'target_operation_id'=>$target,'actor_type'=>$ship?'employee':'customer','actor_id'=>$actor['id'],
 'created_at'=>$at,'committed_at'=>$commit,'shipped_at'=>$ship?$at:null,'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$at,
 'plan'=>$plan,'ranges'=>$refs,'summary'=>$summary,'public_result'=>$public,'aftersale_cut_id'=>$model['last_action'],'aftersale'=>$aftersale];
 $base=['schema'=>1,'action'=>$action,'root_order_id'=>(int)$model['root']['id'],'child_order_id'=>$childId,
 'source_snapshot_sha256'=>$model['source']['fingerprint'],'target_operation_id'=>$target,'allocations'=>$digestParts];
 $values=['id'=>$raw['id'],'contract_version'=>2,'root_order_id'=>(int)$model['root']['id'],'child_order_id'=>$childId,'uid'=>(int)$model['root']['uid'],
 'mer_id'=>(int)$model['root']['mer_id'],'action'=>$action,'idem_key'=>$body['idem_key'],'request_digest'=>hash('sha256',m5pcp_canonical($request),true),
 'source_snapshot_digest'=>hex2bin($model['source']['fingerprint']),'allocation_digest'=>hash('sha256',m5pcp_canonical(['schema'=>2,'base'=>$base,
 'aftersale_cut_id'=>$model['last_action'],'aftersale'=>$aftersale]),true),'request_payload'=>['json'=>$request],'result_payload'=>['json'=>$internal],
 'state'=>1,'ship_child_order_id'=>$ship?$childId:null,'target_operation_id'=>$target,'target_action'=>$ship?null:'ship','actor_type'=>$ship?'employee':'customer',
 'actor_id'=>$actor['id'],'created_at'=>$at,'committed_at'=>$commit,'shipped_at'=>$ship?$at:null,'receipt_due_at'=>$ship?0:null,'received_at'=>$ship?null:$at,
 'aftersale_cut_id'=>$model['last_action']];m4fn_expected_row($before['database']['tables'][$ops]['metadata'],$raw,$values);
 $rows[$ops]=array_merge($before['rows'][$ops],[$raw]);$counts[$ops]=1;$rows[$ranges]=array_merge($before['rows'][$ranges],$newRanges);$counts[$ranges]=count($newRanges);
 $orderRows=array_merge($before['rows'][$orders],$newChildren);$root=m4ar_one($orderRows,'id',$model['root']['id']);$want=$root;
 $want['status']=$summary['to_ship']>0?0:($summary['to_receive']>0?1:2);$orderRows=m4ar_replaced($orderRows,'id',$root['id'],$want);
 $wantChild=$child;$childReceived=0;foreach($model['received']as$p)if(($p['child_order_id']??null)===$childId)$childReceived+=$p['quantity'];$wantChild['status']=$childReceived===(int)$child['total_num']?2:1;$orderRows=m4ar_replaced($orderRows,'id',$childId,$wantChild);$rows[$orders]=$orderRows;
 if((int)$root['status']!==$want['status']){$newLogs=m4ar_new_rows($before['rows']['eb_store_order_status'],$after['rows']['eb_store_order_status']);m4ar_expect(count($newLogs)===1,'TASK78_S2_ONE_REAL_STATUS_TRANSITION');
  m4fn_expected_row($before['database']['tables']['eb_store_order_status']['metadata'],$newLogs[0],['oid'=>(int)$root['id'],
  'change_type'=>$ship?'marketplace_fulfillment_ship':'marketplace_fulfillment_receive','change_message'=>$ship?'多商户履约发货状态更新':'多商户履约收货状态更新','change_time'=>$at]);
  $rows['eb_store_order_status']=array_merge($before['rows']['eb_store_order_status'],$newLogs);$counts['eb_store_order_status']=1;}
 $changes=$ship?m4uwo_sequence($before,$after,$child['order_id'],$interval):[];
 $model['root']=$want;$model['child']=$wantChild;$model['last_operation']=$id;$model['operations'][]=$raw;
 $model['children'][$childId]=$wantChild;return ['rows'=>$rows,'counts'=>$counts,'redis'=>$changes,'operation'=>$public];
}

function m4uwo_added_ids(array $before,array $after,string $pk):array
{$known=[];foreach($before as$r)$known[(string)$r[$pk]]=true;$added=[];foreach($after as$r)if(!isset($known[(string)$r[$pk]]))$added[]=$r;return $added;}

/** Independent pure expected read models for the fixed two-child normal path; no production formatter calls. */
function m4uwo_package(array $m,int $childId,bool $detail):array
{
 $child=$m['children'][$childId];$items=[];$q=0;$received=0;$last=null;$ship=null;
 foreach($m['operations']as$op)if((int)$op['child_order_id']===$childId){if($op['action']==='ship')$ship=$op;else $last=max($last??0,(int)$op['received_at']);}
 m4uib_assert(is_array($ship),'OBS_INDEPENDENT_KNOWN_CHILD_SHIP_OPERATION');
 foreach($m['ship_ranges']as$r)if((int)$r['child_order_id']===$childId){$display=null;foreach($m['source']['display']as$d)if($d['id']===(int)$r['source_item_id'])$display=$d;
  m4uib_assert(is_array($display),'OBS_PACKAGE_ORIGINAL_DISPLAY_ITEM');$item=$display;$item['id']=(int)$r['child_item_id'];$item['quantity']=(int)$r['quantity'];
  $item['line_price']=$r['goods'];$item['postage_price']=$r['postage'];$items[]=$item;$q+=(int)$r['quantity'];}
 foreach($m['received']as$r)if(($r['child_order_id']??null)===$childId)$received+=$r['quantity'];usort($items,static fn(array$a,array$b):int=>$a['id']<=>$b['id']);
 $as=m4ar_aftersale($m);$as['pending_cancel_quantity']=0;$as['pending_return_quantity']=0;$as['returned_after_receipt']=0;$as['fulfillment_completed_at']=$q===$received?$last:null;
 $out=['schema'=>2,'scope'=>'marketplace_fulfillment_child_aftersale_v1','id'=>$childId,'order_id'=>$child['order_id'],
  'root_order_id'=>(int)$m['root']['id'],'root_order_no'=>$m['root']['order_id'],'marketplace_trade_version'=>1,'merchant_name_snapshot'=>$child['merchant_name_snapshot'],
  'shipping_type'=>1,'carrier_code'=>$child['delivery_code'],'carrier_name'=>$child['delivery_name'],'tracking_no'=>$child['delivery_id'],
  'shipped_at'=>(int)$ship['shipped_at'],'receipt_due_at'=>0,'last_received_at'=>$last,'received_at'=>$q===$received?$last:null,
  'shipping_state'=>'shipped','receipt_state'=>$received===0?'pending':($received===$q?'received':'partial'),'closed'=>$q===$received,
  'original'=>$q,'shipped'=>$q,'received'=>$received,'cancelled_unshipped'=>0,'returned_accepted'=>0,'to_ship'=>0,'to_receive'=>$q-$received,'items'=>$items,
  'payment_owner_order_id'=>(int)$m['root']['id'],'paid'=>1,'pay_type'=>$m['root']['pay_type'],'pay_time'=>(int)$m['root']['pay_time'],'pay_type_name'=>'测试支付','aftersale'=>$as];
 if($detail){$out['recipient']=[];foreach(['real_name','user_phone','user_address']as$k)$out['recipient'][$k]=$child[$k];$out['mark']=$child['mark'];$out['custom_form']=json_decode($child['custom_form'],true,64,JSON_THROW_ON_ERROR);}
 return $out;
}
function m4uwo_merchant_packages(array $m,int $page,int $limit):array
{
 $root=m4fn_merchant_oracle($m['source'],$m['root'],m4uwo_summary($m),true);$root['schema']=2;$root['scope']='marketplace_order_aftersale_v1';$root['aftersale']=m4ar_aftersale($m);
 $ships=[];foreach($m['operations']as$op)if($op['action']==='ship')$ships[]=$op;usort($ships,static fn(array$a,array$b):int=>bccomp((string)$b['id'],(string)$a['id'],0));
 $list=[];foreach(array_slice($ships,($page-1)*$limit,$limit)as$op)$list[]=m4uwo_package($m,(int)$op['child_order_id'],false);$shipping=[];
 foreach($m['source']['lines']as$id=>$line){$shipped=0;foreach($m['shipped']as$r)if($r['source_item_id']===$id)$shipped+=$r['quantity'];
  $shipping[]=['item_id'=>$id,'original_quantity'=>$line['original_quantity'],'shipped_quantity'=>$shipped,'to_ship'=>$line['original_quantity']-$shipped];}
 return ['root'=>$root,'packages'=>['page'=>$page,'limit'=>$limit,'count'=>count($ships),'list'=>$list],'shipping_items'=>$shipping];
}
function m4uwo_receipt_view(array $m,int $childId):array
{
 $package=m4uwo_package($m,$childId,true);$items=[];$total=0;
 foreach($m['ship_ranges']as$ship)if((int)$ship['child_order_id']===$childId){$received=[];
  foreach($m['received']as$r)if(($r['child_order_id']??null)===$childId&&$r['source_item_id']===(int)$ship['source_item_id'])
   for($i=$r['unit_start'];$i<$r['unit_end'];$i++)$received[$i-(int)$ship['unit_start']+1]=true;
  $segments=[];$start=null;$q=(int)$ship['quantity'];for($i=1;$i<=$q+1;$i++){
   if($i<=$q&&!isset($received[$i])){if($start===null)$start=$i;}elseif($start!==null){$segments[]=['first'=>$start,'last'=>$i-1];$start=null;}}
  $free=$q-count($received);$total+=$free;$items[]=['item_id'=>(int)$ship['child_item_id'],'quantity'=>$q,'received_quantity'=>count($received),'returned_quantity'=>0,
   'returned_after_receipt'=>0,'paused_quantity'=>0,'eligible_quantity'=>$free,'eligible_segments'=>$segments];}
 usort($items,static fn(array$a,array$b):int=>$a['item_id']<=>$b['item_id']);return ['package'=>$package,'allowed_actions'=>['receive'=>$total>0],
  'receipt_selection'=>['schema'=>2,'coordinate'=>'child_item_ordinal','items'=>$items]];
}
function m4uwo_quote(array $m,array $snapshot,int $page,int $limit):array
{
 $base=$m;$base['child']=null;$quote=m4ar_quote($base,$snapshot,$page,$limit);$ships=[];foreach($m['operations']as$r)if($r['action']==='ship')$ships[]=$r;
 usort($ships,static fn(array$a,array$b):int=>bccomp((string)$b['id'],(string)$a['id'],0));$list=[];
 foreach(array_slice($ships,($page-1)*$limit,$limit)as$op){$cid=(int)$op['child_order_id'];$returns=[];
  foreach($m['ship_ranges']as$r)if((int)$r['child_order_id']===$cid)$returns[]=['item_id'=>(int)$r['child_item_id'],'quantity'=>(int)$r['quantity'],
   'eligible_quantity'=>(int)$r['quantity'],'eligible_segments'=>[['first'=>1,'last'=>(int)$r['quantity']]]];
  usort($returns,static fn(array$a,array$b):int=>$a['item_id']<=>$b['item_id']);$list[]=['package'=>m4uwo_package($m,$cid,false),'return_selection'=>['schema'=>1,'coordinate'=>'child_item_ordinal','items'=>$returns]];}
 $quote['packages']=['page'=>$page,'limit'=>$limit,'count'=>count($ships),'list'=>$list];return $quote;
}
function m4uwo_fulfillment_parts(string $name,array $m,array $body):array
{
 $root=(int)$m['root']['id'];m4uib_assert(is_string($body['idem_key']??null)&&preg_match('/\A[!-~]{1,128}\z/D',$body['idem_key'])===1,'OBS_ACTUAL_NORMAL_IDEMPOTENCY_WIRE');
 if(str_starts_with($name,'ship-')){$source=$name==='ship-q2'?$m['source']['q2']:$m['source']['q1'];$q=$name==='ship-q2'?2:1;m4uib_keys($body,['idem_key','items','delivery']);
  m4uib_assert(m4uib_same($body['items'],[['item_id'=>$source,'quantity'=>$q]]),'OBS_INDEPENDENT_EXACT_Q2_OR_Q1_SHIPMENT_SELECTION');
  m4uib_keys($body['delivery'],['type','carrier_code','carrier_name','tracking_no']);m4uib_assert($body['delivery']['type']==='express','OBS_NORMAL_EXPRESS_WIRE');
  foreach(['carrier_code'=>50,'carrier_name'=>64,'tracking_no'=>64]as$k=>$max){$v=$body['delivery'][$k];m4uib_assert(is_string($v)&&strlen($v)>=1&&strlen($v)<=$max&&trim($v)===$v&&!str_contains($v,"\0"),'OBS_ACTUAL_NORMAL_DELIVERY_FORM_VALUE');}
  return ['action'=>'ship','target'=>0,'parts'=>[m4ar_part($m,$source,0,$q)],'path'=>'/merchantapi/orders/'.$root.'/shipments'];
 }
 m4uib_keys($body,['idem_key','selection']);$source=$name==='receive-q1-all-eligible'?$m['source']['q1']:$m['source']['q2'];$ships=[];
 foreach($m['ship_ranges']as$r)if((int)$r['source_item_id']===$source)$ships[]=$r;m4uib_assert(count($ships)===1,'OBS_NORMAL_RECEIPT_INDEPENDENT_SHIP_PARENT');$ship=$ships[0];
 $start=$name==='receive-q2-all-eligible'?1:0;$end=$name==='receive-q2-partial'?1:($source===$m['source']['q2']?2:1);
 $want=$name==='receive-q2-partial'?['mode'=>'items','items'=>[['item_id'=>(int)$ship['child_item_id'],'segments'=>[['first'=>1,'last'=>1]]]]]:['mode'=>'all_eligible'];
 m4uib_assert(m4uib_same($body['selection'],$want),'OBS_EXACT_PARTIAL_OR_ALL_ELIGIBLE_NORMAL_RECEIPT_BODY');$cid=(int)$ship['child_order_id'];
 return ['action'=>'receive','target'=>$cid,'parts'=>[m4ar_part($m,$source,$start,$end,$ship)],'path'=>'/api/order/fulfillment/packages/'.$m['children'][$cid]['order_id'].'/receipt'];
}
function m4uwo_site(array $before):string
{
 $plan=['original'=>['config'=>$before['rows']['eb_system_config']],'native_before'=>$before,'config_reads'=>[],'config_stack'=>[]];
 $site=m4uws_config($plan,'site_url','');m4uib_assert(is_string($site)&&strlen($site)<=2048&&!str_contains($site,"\0"),'OBS_EFFECTIVE_NATIVE_ORIGINAL_SITE_PREFIX_ALLOWS_MISSING_OR_EMPTY');
 return $site;
}
function m4uwo_review_model(array $before,array $after,array $m,array $body,array $answer,array $interval,array $upload):array
{
 $root=$m['root'];$id=(int)$root['id'];$itemId=$m['source']['q2'];$item=m4ar_one($before['rows']['eb_store_order_cart_info'],'id',$itemId);
 m4uib_assert(m4uwo_summary($m)['received']===3&&(int)$root['status']===2,'OBS_ALL_ORIGINAL_UNITS_RECEIVED_BEFORE_REVIEW');m4uib_keys($body,['schema','product_score','service_score','comment','pics']);
 m4uib_assert($body['schema']===1&&$body['product_score']===5&&$body['service_score']===5&&is_string($body['comment'])&&trim($body['comment'])!==''
  &&mb_strlen($body['comment'],'UTF-8')<=512&&m4uib_same($body['pics'],[$upload['reference']]),'OBS_ACTUAL_NONEMPTY_REVIEW_FORM_AND_SAVED_PUBLIC_ATTACHMENT');
 $table='eb_store_product_reply';$new=m4uwo_added_ids($before['rows'][$table],$after['rows'][$table],'id');m4uib_assert(count($new)===1,'OBS_ONE_NEW_REVIEW_ROW');$actual=$new[0];$at=m4upo_time($actual['add_time'],$interval);
 $user=m4ar_one($before['rows']['eb_user'],'uid',(int)$root['uid']);$cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR);$sku=$cart['productInfo']['attrInfo'];
 $row=m4upo_row($before,$actual,$table,['id'=>m4upo_next($before,$table),'uid'=>(int)$root['uid'],'oid'=>$id,'unique'=>$item['unique'],'product_id'=>(int)$item['product_id'],
  'reply_type'=>'product','product_score'=>5,'service_score'=>5,'comment'=>$body['comment'],'pics'=>json_encode($body['pics'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
  'add_time'=>$at,'merchant_reply_content'=>'','merchant_reply_time'=>0,'is_del'=>0,'is_reply'=>0,'nickname'=>$user['nickname'],'avatar'=>$user['avatar'],'suk'=>$sku['suk'],'status'=>0,'mer_id'=>(int)$root['mer_id']]);
 m4uib_assert(m4uib_same($answer,m4ar_review_view($row,$itemId,m4uwo_site($before))),'OBS_EXACT_INDEPENDENT_NORMAL_REVIEW_VIEW');
 return ['rows'=>[$table=>array_merge($before['rows'][$table],[$row])],'counts'=>[$table=>1],'redis'=>[],'review'=>$row,'item'=>$item];
}
function m4uwo_review_change(array $before,array $after,array $m,array $review,array $body,array $answer,array $interval,bool $moderate):array
{
 $table='eb_store_product_reply';$actual=m4ar_one($after['rows'][$table],'id',$review['id']);$want=$review;
 if($moderate){m4uib_assert(m4uib_same($body,['schema'=>1,'status'=>1]),'OBS_ACTUAL_NORMAL_APPROVE_REVIEW_FORM');$want['status']=1;}
 else{m4uib_keys($body,['schema','content']);m4uib_assert($body['schema']===1&&is_string($body['content'])&&trim($body['content'])!==''&&mb_strlen($body['content'],'UTF-8')<=512,'OBS_ACTUAL_NORMAL_MERCHANT_REPLY_FORM');
  $want['merchant_reply_content']=$body['content'];$want['merchant_reply_time']=$actual['merchant_reply_time'];m4upo_time($want['merchant_reply_time'],$interval);$want['is_reply']=1;}
 m4uib_assert($actual===$want&&m4uib_same($answer,m4ar_review_view($want,$m['source']['q2'],m4uwo_site($before))),'OBS_EXACT_ONE_MODERATION_OR_THREE_REPLY_COLUMNS_AND_VIEW');$changed=[];
 foreach($review as$k=>$v)if($want[$k]!==$v)$changed[]=$k;
 return ['rows'=>[$table=>m4ar_replaced($before['rows'][$table],'id',$review['id'],$want)],'counts'=>[],'redis'=>[],'review'=>$want,'changed_fields'=>$changed];
}
function m4uwo_public_review_expected(array $snapshot,array $item,array $review,array $request):void
{
 $id=(int)$item['product_id'];$rows=[];foreach($snapshot['rows']['eb_store_product_reply']as$r)
  if((int)$r['product_id']===$id&&(int)$r['is_del']===0&&(int)$r['status']===1)$rows[]=$r;
 $pending=(int)$review['status']===0;m4uib_assert($pending?$rows===[]:$rows===[$review],'OBS_FULL_NATIVE_PUBLIC_REVIEW_PREDICATE');$response=m4uwo_plain($request['response_public']);
 if($request['path']==='/api/reply/config/'.$id){$want=$pending?['sum_count'=>0,'good_count'=>0,'in_count'=>0,'poor_count'=>0,'reply_chance'=>100,'reply_star'=>5]:
  ['sum_count'=>1,'good_count'=>1,'in_count'=>0,'poor_count'=>0,'reply_chance'=>'100','reply_star'=>'5'];m4uib_assert(m4uib_same($response,$want),'OBS_NORMAL_PUBLIC_COUNT_DEFAULT_TYPES_AND_VALUES');return;}
 m4uib_assert($request['path']==='/api/reply/list/'.$id.'?page=1&limit=20&type=0','OBS_REAL_NORMAL_PUBLIC_COMMENT_PAGE20');
 if($pending){m4uib_assert($response===[],'OBS_PENDING_REVIEW_ACTUALLY_NOT_PUBLIC');return;}
 m4uib_assert(is_array($response)&&array_is_list($response)&&count($response)===1,'OBS_ONE_APPROVED_PUBLIC_LIST_ROW');$actual=$response[0];
 $own=m4ar_review_view($review,(int)$item['id'],m4uwo_site($snapshot));$user=m4ar_one($snapshot['rows']['eb_user'],'uid',$review['uid']);
 $fixed=['id'=>(int)$review['id'],'product_id'=>$id,'nickname'=>$own['nickname'],'avatar'=>$review['avatar'],'is_money_level'=>(int)$user['is_money_level'],'star'=>'5',
  'suk'=>$review['suk'],'comment'=>$review['comment'],'pics'=>$own['pics'],'merchant_reply_content'=>$review['merchant_reply_content'],
  'merchant_reply_time'=>(new DateTimeImmutable('@'.(int)$review['merchant_reply_time']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i')];
 $interval=m4uwo_interval($request);m4uib_assert($interval[1]-$interval[0]<=60,'OBS_BOUNDED_REAL_PUBLIC_RELATIVE_TIME_WINDOW');$times=[];
 foreach(range($interval[0],$interval[1])as$at)$times[]=m4ar_relative_time((int)$review['add_time'],$at);m4uib_assert(in_array($actual['add_time']??null,$times,true),'OBS_SOURCE_CREATED_TIME_IN_REAL_PUBLIC_INTERVAL');
 $fixed['add_time']=$actual['add_time'];m4uib_assert(m4uib_same($fixed,$actual),'OBS_EXACT_TWELVE_FIELD_PUBLIC_ROW');
}

/** Ordinary network evidence stays in this original observer process RAM. No transport/HTTP producer. */
function m4uwo_private_phase(mixed $input):array
{
 if($input===null)return ['login_token'=>null,'ticket_pairs'=>[],'content_responses'=>[]];m4uib_assert($input instanceof stdClass,'OBS_PRIVATE_NORMAL_PHASE_OBJECT');$p=m4uwo_plain($input);
 m4uib_keys($p,['login_token','ticket_pairs','content_responses']);m4uib_assert(($p['login_token']===null||is_string($p['login_token']))
  &&is_array($p['ticket_pairs'])&&array_is_list($p['ticket_pairs'])&&count($p['ticket_pairs'])<=16&&is_array($p['content_responses'])
  &&array_is_list($p['content_responses'])&&count($p['content_responses'])<=16,'OBS_FINITE_NORMAL_PRIVATE_OBSERVATIONS');return $p;
}
function m4uwo_content(array $private,int $sequence,array $descriptor,bool $confidential):void
{
 $found=[];foreach($private['content_responses']as$r){m4uib_keys($r,['sequence','headers','body_base64']);if($r['sequence']===$sequence)$found[]=$r;}
 m4uib_assert(count($found)===1,'OBS_UNIQUE_ACTUAL_NORMAL_RESPONSE_FULL_BYTES');$r=$found[0];m4uib_assert(is_string($r['body_base64'])&&strlen($r['body_base64'])<=1048576&&is_array($r['headers']),'OBS_NORMAL_RESPONSE_RAM_ONLY');
 $bytes=base64_decode($r['body_base64'],true);m4uib_assert(is_string($bytes)&&base64_encode($bytes)===$r['body_base64']&&strlen($bytes)===$descriptor['bytes']
  &&hash('sha256',$bytes)===$descriptor['sha256']&&($r['headers']['content-type']??null)==='image/png','OBS_FULL_NATIVE_OBJECT_EQUALS_ACTUAL_BROWSER_RESPONSE_BYTES');
 $frozen=m4uib_file('/workspace/crmeb/public/__ms4_fixture__/product.png',1048576,true);m4uib_assert($bytes===$frozen['raw'],'OBS_COMPLETE_NORMAL_RESPONSE_BYTES_EQUAL_FROZEN_IMAGE');
 $size=getimagesizefromstring($bytes);$image=imagecreatefromstring($bytes);m4uib_assert(is_array($size)&&$size[0]===m4uwo_png_source()['width']&&$size[1]===m4uwo_png_source()['height']&&$size[2]===IMAGETYPE_PNG&&$image instanceof GdImage&&imagesx($image)===m4uwo_png_source()['width']&&imagesy($image)===m4uwo_png_source()['height'],'OBS_ACTUAL_NORMAL_RESPONSE_REAL_PNG_DECODE');imagedestroy($image);unset($frozen);
 m4uib_assert(($r['headers']['content-length']??null)===(string)$descriptor['bytes'],'OBS_REAL_NORMAL_IMAGE_RESPONSE_EXACT_CONTENT_LENGTH');
 if($confidential)m4uib_assert(($r['headers']['content-length']??null)===(string)$descriptor['bytes']&&($r['headers']['cache-control']??null)==='private, no-store, max-age=0'
  &&($r['headers']['x-content-type-options']??null)==='nosniff','OBS_PRIVATE_NORMAL_CONTENT_HEADERS');unset($bytes);
}
function m4uwo_private_pairs(array $before,array $after,array $event,array $private,array $file,array $descriptor,array &$state):array
{
 $used=[];$result=[];$prefix=$event['role']==='merchant'?'/merchantapi/aftersale-files/':'/api/order/refund/files/';$ticketPath=$prefix.$file['id'].'/read-ticket';$contentPath=$prefix.$file['id'].'/content';
 foreach($private['ticket_pairs']as$pair){m4uib_keys($pair,['issued_sequence','read_sequence','read_ticket','request_ticket']);
  m4uib_assert(is_int($pair['issued_sequence'])&&is_int($pair['read_sequence'])&&$pair['issued_sequence']<$pair['read_sequence']
   &&!isset($used[$pair['issued_sequence']])&&!isset($used[$pair['read_sequence']])&&is_string($pair['read_ticket'])
   &&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$pair['read_ticket'])===1&&hash_equals($pair['read_ticket'],$pair['request_ticket']),'OBS_NORMAL_TICKET_RESPONSE_AND_REQUEST_HEADER_SAME_RAM_SECRET');
  $issue=$event['requests'][$pair['issued_sequence']]??null;$read=$event['requests'][$pair['read_sequence']]??null;m4uib_assert(is_array($issue)&&is_array($read)
   &&$issue['method']==='POST'&&$issue['path']===$ticketPath&&$read['method']==='GET'&&$read['path']===$contentPath
   &&$issue['finished_at_ms']<=$read['started_at_ms']&&$issue['http_status']===200&&$issue['app_status']===200&&$read['http_status']===200,'OBS_ACTUAL_NORMAL_PAIRED_PRIVATE_REQUESTS');
  m4uib_assert(m4uib_same(m4uwo_plain($issue['request_public']),['schema'=>1]),'OBS_NORMAL_READ_TICKET_SCHEMA');$v=m4uwo_plain($issue['response_public']);
  m4uib_keys($v,['schema','scope','file','refund_id','issued_at','expires_at']);$at=m4upo_time($v['issued_at'],m4uwo_interval($issue));
  m4uib_assert($v['schema']===1&&$v['scope']==='marketplace_aftersale_read_ticket_v1'&&$v['expires_at']===$at+300
   &&m4uib_same($v['file'],m4uwo_file_view($file))&&$v['refund_id']===((($pair['issued_sequence']<$state['primary_sequence'])?$state['model_before']:$state['model'])['refunds']===[]?null:(int)array_key_first((($pair['issued_sequence']<$state['primary_sequence'])?$state['model_before']:$state['model'])['refunds'])),'OBS_INDEPENDENT_NORMAL_TICKET_PUBLIC_REDACTION');
  $key='c:mp_aftersale_read:'.hash('sha256',$pair['read_ticket']);m4uib_assert(!isset($before['native_redis'][$key])&&!isset($after['native_redis'][$key])
   &&!isset($state['used_ticket_hashes'][hash('sha256',$pair['read_ticket'])]),'OBS_UNIQUE_NORMAL_TICKET_PAIR_NET_ABSENCE_NOT_INTERMEDIATE_NX_PROOF');
  m4uib_assert($read['app_status']===null&&$read['request_public'] instanceof stdClass&&get_object_vars($read['request_public'])===[]
   &&m4uwo_json($read['response_public'])===m4uwo_json($descriptor+['mime_type'=>'image/png']),'OBS_PRIVATE_CONTENT_PUBLIC_SAFE_DESCRIPTOR_ONLY');m4uwo_content($private,$pair['read_sequence'],$descriptor,true);$state['used_ticket_hashes'][hash('sha256',$pair['read_ticket'])]=true;
  $used[$pair['issued_sequence']]=true;$used[$pair['read_sequence']]=true;$result[]=['issued_sequence'=>$pair['issued_sequence'],'read_sequence'=>$pair['read_sequence'],
   'file_id'=>(string)$file['id'],'full_response_bytes_verified'=>true,'net_ticket_absent'=>true,'native_intermediate_observed'=>false];
 }
 foreach($event['requests']as$r)if($r['path']===$ticketPath||$r['path']===$contentPath)m4uib_assert(isset($used[$r['sequence']]),'OBS_EVERY_NORMAL_PRIVATE_REQUEST_ACCOUNTED');return ['used'=>$used,'pairs'=>$result];
}
function m4uwo_frozen_png():array
{
 $f=m4uib_file('/workspace/crmeb/public/__ms4_fixture__/product.png',1048576,true);$size=getimagesizefromstring($f['raw']);m4uib_assert($f['descriptor']['bytes']>0&&is_array($size)
  &&$size[0]===m4uwo_png_source()['width']&&$size[1]===m4uwo_png_source()['height']&&$size[2]===IMAGETYPE_PNG&&$f['descriptor']===m4uwo_png_source()['file'],'OBS_ACTUAL_PINNED_PRESELECTED_NONEMPTY_PNG');return ['bytes'=>$f['descriptor']['bytes'],'sha256'=>$f['descriptor']['sha256']];
}
function m4uwo_public_upload_model(array $before,array $after,array $state,array $body,array $answer,array $interval):array
{
 m4uib_keys($body,['filename','file']);$png=m4uwo_frozen_png();m4uib_assert($body['filename']==='file'&&m4uib_same($body['file'],$png+['mime_type'=>'image/png']),'OBS_ACTUAL_NORMAL_PUBLIC_PNG_MULTIPART');
 m4uib_keys($answer,['name','url','attachment_ref']);$ref=$answer['attachment_ref'];m4uib_assert(is_string($ref)&&strlen($ref)<=200&&preg_match('~\A/uploads/store/comment/([0-9]{8})/([a-f0-9]{32})\.png\z~D',$ref,$normalName)===1
  &&!str_contains($ref,'..')&&!str_contains($ref,'//')&&$answer['name']===basename($ref)&&$answer['url']===$state['binding']['origin'].$ref,'OBS_ACTUAL_NORMAL_PUBLIC_ATTACHMENT_SAME_ORIGIN');
 $dates=[];foreach(array_unique([$interval[0],$interval[1]])as$clock)$dates[]=(new DateTimeImmutable('@'.$clock))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Ymd');
 m4uib_assert($interval[1]-$interval[0]<=60&&in_array($normalName[1],$dates,true),'OBS_DEFAULT_FILE_HASHNAME_ACTUAL_REQUEST_DATE_AND_MD5_SHAPE_NOT_CERTIFICATE_RULE');
 $table='eb_system_attachment';$new=m4uwo_added_ids($before['rows'][$table],$after['rows'][$table],'att_id');m4uib_assert(count($new)===1,'OBS_ONE_NORMAL_PUBLIC_ATTACHMENT');$raw=$new[0];$at=m4upo_time($raw['time'],$interval);
 $row=m4upo_row($before,$raw,$table,['att_id'=>m4upo_next($before,$table),'name'=>basename($ref),'att_dir'=>$ref,'satt_dir'=>$ref,'att_size'=>(string)$png['bytes'],'att_type'=>'image/png',
  'pid'=>1,'time'=>$at,'image_type'=>1,'module_type'=>3,'real_name'=>basename($ref),'type'=>0]);
 $uid=$state['prepare']['actor_ids']['customer'];$key='c:start_uploads_'.$uid;$old=$before['native_redis'][$key]??null;$oldCount=0;
 if($old!==null){m4uib_assert($old['type']==='string'&&preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$old['value'])===1,'OBS_SOURCE_UPLOAD_COUNTER_BEFORE');$oldCount=(int)$old['value'];}
 $entry=$after['native_redis'][$key]??null;m4uib_assert(is_array($entry)&&$entry['type']==='string'&&$entry['value']===m4tr_cache_wire($oldCount+1)
  &&$entry['expires']>=$interval[0]*1000+86400000&&$entry['expires']<=($interval[1]+1)*1000+86400000,'OBS_EXACT_NORMAL_UPLOAD_COUNTER_NATIVE_VALUE_DEADLINE');
 $tag='c:CRMEB:'.md5('crmeb');$members=$before['tag_members'][$tag];$members[]=$key;$members=array_values(array_unique($members,SORT_STRING));sort($members,SORT_STRING);$t=$after['native_redis'][$tag]??null;
 // Source upload tag plan is merged with the complete normal startup plan below.
 return ['rows'=>[$table=>array_merge($before['rows'][$table],[$row])],'counts'=>[$table=>1],'redis'=>[$key=>$entry],'tags'=>[$tag=>$members],
  'upload'=>['row'=>$row,'reference'=>$ref,'display_url'=>$answer['url'],'file'=>$png]];
}
function m4uwo_audit_batch(array $before,array $after,array $specs,array $source,array &$rows,array &$counts):void
{
 foreach(['eb_marketplace_audit_log','eb_system_log']as$table){$wanted=[];foreach($specs as$spec)if((($spec['global']??false)?'eb_system_log':'eb_marketplace_audit_log')===$table)$wanted[]=$spec;
  if($wanted===[])continue;$added=m4uwo_added_ids($before['rows'][$table],$after['rows'][$table],'id');usort($added,static fn(array$a,array$b):int=>bccomp((string)$a['id'],(string)$b['id'],0));
  m4uib_assert(count($added)===count($wanted),'OBS_EXACT_NORMAL_ASSOCIATED_AUDIT_COUNT');$expected=[];
  foreach($wanted as$i=>$spec){$r=$added[$i];$at=m4upo_time($r['add_time'],$spec['interval']);$actor=$spec['actor'];$id=m4upo_next($before,$table,$i);
   if($table==='eb_system_log'){$found=[];foreach($before['rows']['eb_system_menus']as$menu)if($menu['api_url']===$spec['rule'])$found[]=$menu;
    m4uib_assert(count($found)<=1,'OBS_REAL_UNAMBIGUOUS_NORMAL_VISIT_LABEL');$v=['id'=>$id,'method'=>'adminapi','admin_id'=>$actor['id'],'add_time'=>$at,'admin_name'=>$actor['account'],
     'path'=>$spec['rule'],'page'=>$found===[]?'未知':($found[0]['menu_name']?:'未知'),'ip'=>m4uwo_gateway_ip($source),'type'=>'system'];
   }else{$detail=json_decode($r['detail'],false,32,JSON_THROW_ON_ERROR);m4uib_assert($detail instanceof stdClass&&m4uib_same(get_object_vars($detail),$spec['detail']??[]),'OBS_AUDIT_COMPLETE_INDEPENDENT_DETAIL_OBJECT');
    $v=['id'=>$id,'mer_id'=>$spec['mer_id'],'actor_type'=>$actor['app']==='merchantapi'?'merchant':'admin','actor_id'=>$actor['id'],'action'=>$spec['action'],
     'resource_type'=>$spec['resource_type'],'resource_id'=>(string)$spec['resource_id'],'result'=>'success','detail'=>$r['detail'],'add_time'=>$at];}
   $expected[]=m4upo_row($before,$r,$table,$v);
  }$rows[$table]=array_merge($before['rows'][$table],$expected);$counts[$table]=count($expected);
 }
}

function m4uwo_actor(array $state,array $before,string $role):array
{
 $id=$state['prepare']['actor_ids'][$role];$app=match($role){'customer'=>'api','merchant'=>'merchantapi','platform'=>'adminapi'};
 $actor=['app'=>$app,'id'=>$id];if($role==='platform'){$r=m4ar_one($before['rows']['eb_system_admin'],'id',$id);$actor['account']=$r['account'];$actor['role_id']=(int)$r['roles'];}
 if($role==='merchant')$actor['mer_id']=$state['prepare']['merchant_id'];return $actor;
}
function m4uwo_auth_touch_phase(array $before,array $after,array $event,int $id):array
{
 $r=m4ar_one($after['rows']['eb_user'],'uid',$id);$old=m4ar_one($before['rows']['eb_user'],'uid',$id);$times=[];
 foreach($event['requests']as$q)if(($q['authentication']['subject_id']??null)===$id&&$q['authentication']['present']===true){$interval=m4uwo_interval($q);
  if((int)$r['last_time']>=$interval[0]&&(int)$r['last_time']<=$interval[1])$times[]=true;}
 $want=$old;$want['last_time']=$r['last_time'];m4uib_assert($times!==[]&&$r===$want,'OBS_ONLY_CUSTOMER_LAST_TIME_BOUND_TO_REAL_ASSOCIATED_AUTH_REQUEST');
 return ['eb_user'=>m4ar_replaced($before['rows']['eb_user'],'uid',$id,$want)];
}
function m4uwo_expect_read(array $r,mixed $expected):void
{m4uib_assert($r['method']==='GET'&&$r['http_status']===200&&$r['app_status']===200&&m4uib_same(m4uwo_plain($r['response_public']),$expected),'OBS_INDEPENDENT_ASSOCIATED_NORMAL_READ_VIEW');}
function m4uwo_review_item(array $state,array $before):array
{return m4ar_one($before['rows']['eb_store_order_cart_info'],'id',$state['source']['q2']);}
function m4uwo_normal_read(array $q,array $before,array $after,array $state,array $oldModel,array $newModel,int $primarySequence,array &$audits):bool
{
 $path=$q['path'];$m=$q['sequence']<$primarySequence?$oldModel:$newModel;$root=(int)$m['root']['id'];$uid=$state['prepare']['actor_ids']['customer'];$mer=$state['prepare']['merchant_id'];
 if($path==='/api/user'){m4uwo_expect_read($q,['uid'=>$uid]);return true;}
 if($path==='/merchantapi/me'){$employee=m4ar_one($before['rows']['eb_marketplace_employee'],'id',$state['prepare']['actor_ids']['merchant']);$role=m4ar_one($before['rows']['eb_marketplace_role'],'id',$employee['role_id']);
  m4uwo_expect_read($q,['employee_id'=>(int)$employee['id'],'merchant_id'=>$mer,'role_id'=>(int)$role['id'],'permissions'=>json_decode($role['permissions'],true,32,JSON_THROW_ON_ERROR)]);return true;}
 if($path==='/api/order/refund/list?contract=2&page=1&limit=20'){
  $owned=[];foreach($before['rows']['eb_store_order_refund']as$r)if((int)$r['uid']===$uid)$owned[]=$r;
  m4uib_assert(count($owned)===count($m['refunds']),'OBS_COMPLETE_OWNED_REFUND_PREDICATE_MODELED');$records=array_values($m['refunds']);usort($records,static fn(array$a,array$b):int=>$b['id']<=>$a['id']);$list=[];
  foreach($records as$r)$list[]=m4ar_refund_view($m,$r,$r['state'],m4uwo_actor($state,$before,'customer'),$r['updated_at']);
  m4uwo_expect_read($q,['schema'=>2,'page'=>1,'limit'=>20,'count'=>count($records),'list'=>$list]);return true;}
 if($path==='/api/order/refund/cart_info/'.$root.'?contract=2&fulfillment_page=1&fulfillment_limit=20'){m4uwo_expect_read($q,m4uwo_quote($m,$before,1,20));return true;}
 if($path==='/merchantapi/orders/'.$root.'/packages?page=1&limit=20'){m4uwo_expect_read($q,m4uwo_merchant_packages($m,1,20));return true;}
 foreach($m['children']as$cid=>$child){if($path==='/api/order/fulfillment/packages/'.$child['order_id']){m4uwo_expect_read($q,m4uwo_receipt_view($m,$cid));return true;}
  if($path==='/merchantapi/packages/'.$cid){m4uwo_expect_read($q,['package'=>m4uwo_package($m,$cid,true)]);return true;}}
 foreach($m['refunds']as$refund){$actor=null;
  if($path==='/api/order/refund/detail/'.$refund['number'])$actor=m4uwo_actor($state,$before,'customer');
  if($path==='/merchantapi/aftersales/'.$refund['id'])$actor=m4uwo_actor($state,$before,'merchant');
  if($actor!==null){m4uwo_expect_read($q,m4ar_refund_view($m,$refund,$refund['state'],$actor,$refund['updated_at']));return true;}}
 $item=m4uwo_review_item($state,$before);$review=$q['sequence']<$primarySequence?($state['review_before']??null):($state['review']??null);
 if($path==='/api/order/reviews/'.$root.'/'.$item['id']){m4uwo_expect_read($q,m4ar_review_quote($m['root'],$item,$review,m4uwo_site($before),m4uwo_summary($m)['received']===3));return true;}
 if($review!==null&&in_array($path,['/merchantapi/reviews/'.$review['id'],'/adminapi/marketplace/reviews/'.$review['id']],true)){
  m4uwo_expect_read($q,m4ar_review_view($review,(int)$item['id'],m4uwo_site($before)));
  if(str_starts_with($path,'/adminapi/'))$audits[]=['actor'=>m4uwo_actor($state,$before,'platform'),'mer_id'=>$mer,'action'=>'marketplace-reviews-read','resource_type'=>'review','resource_id'=>$review['id'],'interval'=>m4uwo_interval($q),'sequence'=>$q['sequence']];return true;}
 if($path==='/adminapi/menus'){
  // Auxiliary permission data is omitted. Signed identity, unchanged native grants and actual authorized business/audit are checked independently.
  m4uwo_expect_read($q,null);return true;}
 if($path==='/api/order/detail/'.$m['root']['order_id'].'?fulfillment_page=1&fulfillment_limit=20'){
  m4uib_assert($q['method']==='GET'&&$q['app_status']===200&&$q['response_public']===null,'OBS_NORMAL_ROOT_NAVIGATION_WITH_CHILD_FULL_DTO_VERIFIED_SEPARATELY');return true;}
 return false;
}
function m4uwo_verify_twenty(string $name,array $before,array $after,array $input,array &$state,array $source):array
{
 $wire=$input['wire'];$event=$input['event'];$private=m4uwo_private_phase($input['private']);$role=$wire['actor_role'];$actorRole=$role==='public'?'customer':$role;
 $actor=m4uwo_actor($state,$before,$actorRole);$id=$actor['id'];$historical=$input['historical_image'];
 $primary=$historical?null:$event['requests'][$input['primary_sequence']];$seq=$historical?-1:$primary['sequence'];
 $interval=$historical?[intdiv($event['started_at_ms'],1000),intdiv($event['finished_at_ms'],1000)]:m4uwo_interval($primary);
 $root=$state['prepare']['paid_order_id'];$body=$wire['body_public'];$answer=$wire['response_public'];$rows=[];$counts=[];$redis=[];$tags=[];$changedRoots=[];$used=[];$audits=[];$facts=[];
 $startup=m4uws_plan($state,$before);$startup['event']=$event;$startup['primary_sequence']=$seq;$state['login_native_plan']=['redis'=>[],'tags'=>[]];$newImageWitness=null;
 if(!isset($state['model']))$state['model']=m4uwo_model_start($state,$before);$oldModel=$state['model'];$state['review_before']=$state['review']??null;$state['upload_before']=$state['upload']??null;
 if(str_starts_with($name,'login-')){
  $path=match($role){'customer'=>'/api/login','merchant'=>'/merchantapi/login','platform'=>'/adminapi/login'};
  m4uib_assert($wire['method']==='POST'&&$wire['path']===$path&&$wire['body_is_empty_object'],'OBS_ACTUAL_NORMAL_CREDENTIAL_FORM_POST');m4uib_keys($answer,['subject_id']);m4uib_assert($answer['subject_id']===$id,'OBS_NORMAL_RESPONSE_SUBJECT_FROM_REAL_LOGIN');
  if($role==='customer'){m4uib_assert(is_string($private['login_token'])&&$private['login_token']!=='','OBS_CUSTOMER_ACTUAL_NORMAL_LOGIN_RESPONSE_TOKEN_REQUIRED_FOR_EXACT_BROWSER_JOIN');$old=m4ar_one($before['rows']['eb_user'],'uid',$id);$now=m4ar_one($after['rows']['eb_user'],'uid',$id);$want=$old;$want['last_time']=$now['last_time'];$want['last_ip']=m4uwo_gateway_ip($source);
   m4ar_time(intdiv($event['started_at_ms'],1000),intdiv($event['finished_at_ms'],1000),$want['last_time']);m4uib_assert($now===$want,'OBS_LOGIN_CUSTOMER_ONLY_NATIVE_STATS');$rows['eb_user']=m4ar_replaced($before['rows']['eb_user'],'uid',$id,$want);
   $facts['login']=m4uwo_login_customer($before,$after,$id,$primary,$private['login_token'],$state);
  }else{
   m4uib_assert(is_string($private['login_token'])&&$private['login_token']!=='','OBS_REAL_NORMAL_LOGIN_RESPONSE_REQUIRED_FOR_SIGNED_V2_ISSUER');
   if($role==='platform'){$old=m4ar_one($before['rows']['eb_system_admin'],'id',$id);$now=m4ar_one($after['rows']['eb_system_admin'],'id',$id);$want=$old;$want['login_count']=(int)$old['login_count']+1;
    $want['last_time']=$now['last_time'];$want['last_ip']=m4uwo_gateway_ip($source);m4upo_time($want['last_time'],$interval);m4uib_assert($now===$want,'OBS_ADMIN_LOGIN_ONLY_EXACT_STATS');$rows['eb_system_admin']=m4ar_replaced($before['rows']['eb_system_admin'],'id',$id,$want);}
   $facts['login']=m4uwo_login_v2($before,$after,$actor['app'],$role,$id,$primary,$private['login_token'],$state);
  }$used[$seq]=true;
  if($role!=='platform'){$identityPath=$role==='customer'?'/api/user':'/merchantapi/me';$joined=0;
   foreach($event['requests']as$q)if($q['sequence']>$seq&&$q['method']==='GET'&&$q['path']===$identityPath&&$q['authentication']['present']===true
    &&$q['authentication']['subject_id']===$id&&$q['started_at_ms']>=$primary['finished_at_ms'])++$joined;
   m4uib_assert($joined>=1,'OBS_LOGIN_SUBJECT_FROM_REAL_SUBSEQUENT_NORMAL_PROFILE_READ_NOT_UNSIGNED_DECODE');}

 }elseif($name==='n1-initial-read'){
  m4uib_assert($wire['method']==='GET'&&$wire['path']==='/api/order/refund/cart_info/'.$root.'?contract=2&fulfillment_page=1&fulfillment_limit=20'&&$wire['body_is_empty_object'],'OBS_REAL_NORMAL_INITIAL_QUOTE20');
  foreach($before['rows']['eb_store_order_refund']as$r)m4uib_assert((int)$r['store_order_id']!==$root,'OBS_PREPARED_ROOT_HAS_NO_PRIOR_REFUND');m4uwo_expect_read($primary,m4uwo_quote($state['model'],$before,1,20));$used[$seq]=true;
 }elseif($name==='n1-private-evidence-upload'){
  m4uib_assert($wire['method']==='POST'&&$wire['path']==='/api/order/refund/files/'.$root,'OBS_REAL_NORMAL_PRIVATE_UPLOAD');m4uib_keys($body,['schema','upload_key','file']);$png=m4uwo_frozen_png();
  m4uib_assert($body['schema']==='1'&&is_string($body['upload_key'])&&strlen($body['upload_key'])>=1&&strlen($body['upload_key'])<=128&&m4uib_same($body['file'],$png+['mime_type'=>'image/png']),'OBS_NORMAL_PRIVATE_MULTIPART_SOURCE_FIELDS');
  $t='eb_marketplace_aftersale_file';$new=m4uwo_added_ids($before['rows'][$t],$after['rows'][$t],'id');m4uib_assert(count($new)===1,'OBS_ONE_NORMAL_PRIVATE_UPLOAD_ROW');$r=$new[0];$at=m4upo_time($r['created_at'],$interval);m4uib_assert(m4uib_hex($r['object_key']),'OBS_REAL_RANDOM_PRIVATE_OBJECT_KEY');
  $want=m4upo_row($before,$r,$t,['id'=>m4upo_next($before,$t),'uid'=>$id,'root_order_id'=>$root,'mer_id'=>$state['prepare']['merchant_id'],'refund_id'=>null,'image_position'=>null,
   'object_key'=>$r['object_key'],'media_type'=>'image/png','byte_size'=>$png['bytes'],'width'=>m4uwo_png_source()['width'],'height'=>m4uwo_png_source()['height'],'content_sha256'=>hex2bin($png['sha256']),'upload_key'=>$body['upload_key'],
   'state'=>'STAGED','created_at'=>$at,'claim_expires_at'=>$at+86400,'claimed_at'=>null,'purged_at'=>null]);m4uib_assert(m4uib_same($answer,m4uwo_file_view($want)),'OBS_EXACT_NORMAL_PRIVATE_UPLOAD_VIEW');
  $rows[$t]=array_merge($before['rows'][$t],[$want]);$counts[$t]=1;m4uwo_private_file_delta($before,$after,$root.'/'.$r['object_key'].'/content',$png,$interval);$changedRoots=[M4AR_PRIVATE];$state['private_file']=$want;$used[$seq]=true;$facts['private_file_id']=(string)$want['id'];
 }elseif(in_array($name,['n1-money-only-apply','n1-merchant-approve'],true)){
  $apply=$name==='n1-money-only-apply';$m=&$state['model'];$refund=$apply?null:(int)array_key_first($m['refunds']);m4uib_assert($wire['method']==='POST'&&$wire['path']===($apply?'/api/order/refund/apply/'.$root:'/merchantapi/aftersales/'.$refund.'/approve'),'OBS_REAL_NORMAL_N1_ACTION_PATH');
  if($apply){m4uib_keys($body,['schema','idem_key','operation_kind','amount','reason','images','selection']);m4uib_assert($body['schema']===2&&$body['operation_kind']==='money_only'&&$body['amount']==='0.01'
    &&is_string($body['reason'])&&trim($body['reason'])!==''&&m4uib_same($body['images'],[(string)$state['private_file']['id']])
    &&m4uib_same($body['selection'],['mode'=>'root_quantities','items'=>[['item_id'=>$state['source']['q2'],'quantity'=>1]]]),'OBS_EXACT_MONEY_ONLY_ONE_CENT_WITH_NONEMPTY_PRIVATE_EVIDENCE');
  }else{m4uib_keys($body,['schema','idem_key']);m4uib_assert($body['schema']===2,'OBS_NORMAL_APPROVE_SCHEMA2');}
  m4uib_assert(is_string($body['idem_key'])&&strlen($body['idem_key'])>=1&&strlen($body['idem_key'])<=128,'OBS_REAL_UI_GENERATED_N1_IDEMPOTENCY_KEY');
  $v=m4uwo_refund_model($before,$after,$m,$actor,$apply?'apply':'approve',$body,$answer,$interval,$source,$refund);$rows=$v['rows'];$counts=$v['counts'];$redis=$v['redis'];
  $state['private_file']=m4ar_one($after['rows']['eb_marketplace_aftersale_file'],'id',$state['private_file']['id']);$used[$seq]=true;$facts['N1']=['business_state'=>$v['record']['state']['business_state'],
   'physical_state'=>$v['record']['state']['physical_state'],'finance_state'=>$v['record']['state']['finance_state'],'held_amount'=>$v['record']['state']['held_amount'],'confirmed_amount'=>$v['record']['state']['confirmed_amount']];unset($m);
 }elseif($name==='n1-owned-private-preview-read'){
  $record=array_values($state['model']['refunds'])[0];m4uib_assert($wire['method']==='GET'&&$wire['path']==='/api/order/refund/detail/'.$record['number'],'OBS_NORMAL_OWNED_DETAIL_REMOUNT_PRIVATE_PREVIEW');
 }elseif(in_array($name,['ship-q2','receive-q2-partial','receive-q2-all-eligible','ship-q1','receive-q1-all-eligible'],true)){
  $m=&$state['model'];$selection=m4uwo_fulfillment_parts($name,$m,$body);m4uib_assert($wire['method']==='POST'&&$wire['path']===$selection['path'],'OBS_REAL_NORMAL_SHIP_OR_RECEIPT_ENDPOINT');
  $v=m4uwo_operation_model($before,$after,$m,$actor,$selection['action'],$body,$selection['parts'],$answer,$interval,$selection['target']);$rows=$v['rows'];$counts=$v['counts'];$redis=$v['redis'];$used[$seq]=true;
  $facts['fulfillment']=['summary'=>m4uwo_summary($m),'aftersale'=>m4ar_aftersale($m),'child_count'=>count($m['children'])];unset($m);
 }elseif($name==='n2-public-image-upload'){
  m4uib_assert($wire['method']==='POST'&&$wire['path']==='/api/upload/image','OBS_REAL_NORMAL_PUBLIC_UPLOAD_ENDPOINT');$v=m4uwo_public_upload_model($before,$after,$state,$body,$answer,$interval);
  $rows=$v['rows'];$counts=$v['counts'];$redis=$v['redis'];$tags=$v['tags'];$state['upload']=$v['upload'];m4uwo_one_file_delta($before,$after,'/workspace/crmeb/public',ltrim($v['upload']['reference'],'/'),$v['upload']['file'],$interval,0644,0755);
  $changedRoots=['/workspace/crmeb/public'];$used[$seq]=true;$facts['public_image']=$v['upload']['file'];
 }elseif($name==='n2-review-submit'){
  m4uib_assert($wire['method']==='POST'&&$wire['path']==='/api/order/reviews/'.$root.'/'.$state['source']['q2'],'OBS_REAL_NORMAL_REVIEW_CREATE_ENDPOINT');$v=m4uwo_review_model($before,$after,$state['model'],$body,$answer,$interval,$state['upload']);
  $rows=$v['rows'];$counts=$v['counts'];$state['review']=$v['review'];$state['review_item']=$v['item'];$used[$seq]=true;$facts['review_id']=(int)$v['review']['id'];
 }elseif(in_array($name,['n2-platform-moderate','n2-merchant-reply'],true)){
  $moderate=$name==='n2-platform-moderate';$review=$state['review'];m4uib_assert($wire['method']==='PUT'&&$wire['path']===($moderate?'/adminapi/marketplace/reviews/':'/merchantapi/reviews/').$review['id'].($moderate?'/review':'/reply'),'OBS_REAL_NORMAL_REVIEW_COMMAND_PATH');
  $v=m4uwo_review_change($before,$after,$state['model'],$review,$body,$answer,$interval,$moderate);$rows=$v['rows'];$counts=$v['counts'];$state['review']=$v['review'];$used[$seq]=true;
  $audits[]=['actor'=>$actor,'mer_id'=>$state['prepare']['merchant_id'],'action'=>$moderate?'marketplace-reviews-moderate':'review.reply','resource_type'=>'review','resource_id'=>$review['id'],
   'detail'=>['changed_fields'=>$v['changed_fields']],'interval'=>$interval,'sequence'=>$seq];$facts['review_id']=(int)$review['id'];$facts['changed_fields']=$v['changed_fields'];
 }elseif(in_array($name,['n2-pending-public-read','n2-public-review-read','n2-public-image-read'],true)){
  m4uib_assert($wire['method']==='GET'&&$wire['body_is_empty_object'],'OBS_REAL_NORMAL_PUBLIC_READ');
 }else m4uib_assert(false,'OBS_UNKNOWN_TWENTY_CASE_NAME');
 if($name==='n2-public-image-upload')m4uws_upload_configs($startup,$primary);
 if(($state['sequence_barrier']??null)!==null)$facts['sequence_barrier']=$state['sequence_barrier'];
 $newModel=$state['model'];$privateUsed=[];if(!str_starts_with($name,'login-'))m4uib_assert($private['login_token']===null,'OBS_NO_UNRELATED_LOGIN_TOKEN');
 $contentSequences=[];foreach($private['ticket_pairs']as$p)$contentSequences[]=$p['read_sequence'];if(isset($state['upload']))foreach($event['requests']as$q)if($q['path']===$state['upload']['reference'])$contentSequences[]=$q['sequence'];
 $seenContent=[];foreach($private['content_responses']as$r)$seenContent[]=$r['sequence'];sort($contentSequences,SORT_NUMERIC);sort($seenContent,SORT_NUMERIC);m4uib_assert($contentSequences===$seenContent&&count($seenContent)===count(array_unique($seenContent,SORT_NUMERIC)),'OBS_EXACT_ALL_NORMAL_IMAGE_PRIVATE_RESPONSE_SET');
 if(in_array($name,['n1-private-evidence-upload','n1-money-only-apply','n1-owned-private-preview-read','n1-merchant-approve'],true)){
  $pairState=$state;$pairState['model_before']=$oldModel;$pairState['primary_sequence']=$seq;$pairs=m4uwo_private_pairs($before,$after,$event,$private,$state['private_file'],m4uwo_frozen_png(),$pairState);
  $state['used_ticket_hashes']=$pairState['used_ticket_hashes'];m4uib_assert(count($pairs['pairs'])>=1,'OBS_ACTUAL_NORMAL_AUTOMATIC_PRIVATE_PREVIEW_INCLUDED');$privateUsed=$pairs['used'];$facts['private_pairs']=$pairs['pairs'];
 }else m4uib_assert($private['ticket_pairs']===[],'OBS_NO_UNRELATED_PRIVATE_TICKET_INPUT');
 $used+=$privateUsed;$logoutSeen=false;$publicListReads=0;$publicConfigReads=0;$publicImage=0;$mandatoryReadbacks=0;
 foreach($event['requests']as$q){$i=$q['sequence'];$path=$q['path'];$auth=$q['authentication'];
  if(str_starts_with($name,'login-')&&$i===$seq)m4uib_assert(m4uib_same($auth,['present'=>false,'kind'=>'none','subject_id'=>null]),'OBS_NORMAL_CREDENTIAL_POST_NOT_FILLED_FORM_AUTH');
  elseif(isset($state['upload'])&&$path===$state['upload']['reference'])m4uib_assert(m4uib_same($auth,['present'=>false,'kind'=>'none','subject_id'=>null]),'OBS_EXACT_NAMED_PUBLIC_IMAGE_ANONYMOUS_EVEN_IN_CUSTOMER_UPLOAD_STEP');
  elseif(m4uws_is_path($path)){
   if($role==='public'&&($logoutSeen||$name!=='n2-pending-public-read'))m4uib_assert(m4uib_same($auth,['present'=>false,'kind'=>'none','subject_id'=>null]),'OBS_POST_LOGOUT_PUBLIC_STARTUP_REALLY_HAS_NO_AUTHORIZATION');
   else m4uib_assert(m4uib_same($auth,['present'=>false,'kind'=>'none','subject_id'=>null])||m4uib_same($auth,['present'=>true,'kind'=>'Bearer','subject_id'=>$state['prepare']['actor_ids']['customer']]),'OBS_ACTUAL_NORMAL_H5_OPTIONAL_CUSTOMER_AUTH_ONLY');}
  elseif($name==='n2-pending-public-read'&&$path==='/api/logout'){
   m4uib_assert(!$logoutSeen&&$q['method']==='GET'&&$q['app_status']===200&&$auth['present']===true&&$auth['subject_id']===$state['prepare']['actor_ids']['customer'],'OBS_ORDINARY_GUI_LOGOUT_REAL_REQUEST');
   $session=$state['sessions']['customer'];m4uib_assert(isset($before['native_redis'][$session['key']])&&!isset($after['native_redis'][$session['key']]),'OBS_EXACT_NORMAL_LOGOUT_DELETES_ISSUED_CUSTOMER_SESSION');$redis[$session['key']]=null;$logoutSeen=true;$used[$i]=true;
  }elseif($role==='public'&&($logoutSeen||$name!=='n2-pending-public-read'))m4uib_assert(m4uib_same($auth,['present'=>false,'kind'=>'none','subject_id'=>null]),'OBS_ACTUAL_ANONYMOUS_REQUEST_NO_AUTHORIZATION');
  else m4uib_assert($auth['present']===true&&$auth['kind']==='Bearer'&&$auth['subject_id']===$id,'OBS_ASSOCIATED_REAL_NORMAL_ROLE_SESSION');
  if(isset($used[$i]))continue;
  if($role==='public'&&in_array($path,['/api/reply/list/'.$state['review_item']['product_id'].'?page=1&limit=20&type=0','/api/reply/config/'.$state['review_item']['product_id']],true)){
   m4uib_assert($q['authentication']['present']===false,'OBS_PUBLIC_REVIEW_ACTUALLY_ANONYMOUS');m4uwo_public_review_expected($before,$state['review_item'],$state['review'],$q);if(str_starts_with($path,'/api/reply/list/')){m4uib_assert(!m4uws_config($startup,'image_thumb_status',0),'OBS_PUBLIC_REPLY_LIST_NORMAL_NO_THUMB_SOURCE_BRANCH');if((int)$state['review']['status']===1)m4uws_set_file($startup,[]);m4uws_config_calls($startup,$q);++$publicListReads;}else ++$publicConfigReads;$used[$i]=true;continue;}
  if(isset($state['upload'])&&$path===$state['upload']['reference']){m4uib_assert($q['method']==='GET'&&$q['http_status']===200&&$q['authentication']['present']===false,'OBS_NORMAL_ANONYMOUS_PUBLIC_IMAGE_READ');m4uib_assert($q['app_status']===null&&$q['request_public'] instanceof stdClass&&get_object_vars($q['request_public'])===[]
   &&m4uwo_json($q['response_public'])===m4uwo_json($state['upload']['file']+['mime_type'=>'image/png']),'OBS_PUBLIC_CONTENT_EXACT_SAFE_ACTUAL_BYTES_DESCRIPTOR_NO_JSON_APP_STATUS');m4uwo_content($private,$i,$state['upload']['file'],false);$newImageWitness=m4uws_image_witness($state,$event,$q);++$publicImage;$used[$i]=true;continue;}
  if(m4uws_read($startup,$q,$before,$state)){$used[$i]=true;continue;}
  m4uib_assert(m4uwo_normal_read($q,$before,$after,$state,$oldModel,$newModel,$seq,$audits),'OBS_UNKNOWN_ASSOCIATED_NORMAL_REQUEST_REQUIRES_SOURCE_MODEL');
  if($q['sequence']>$seq&&(str_contains($path,'/packages')||str_contains($path,'/api/order/reviews/')||str_contains($path,'/reviews/')||str_contains($path,'/aftersales/')))++$mandatoryReadbacks;$used[$i]=true;
 }
 m4uib_assert(count($used)===count($event['requests']),'OBS_EVERY_ACTUAL_NORMAL_REQUEST_MODELED');
 if(in_array($name,['ship-q2','ship-q1','receive-q2-partial','receive-q2-all-eligible','receive-q1-all-eligible','n2-review-submit','n2-platform-moderate','n2-merchant-reply'],true))m4uib_assert($mandatoryReadbacks>=1,'OBS_MANDATORY_REAL_NORMAL_POST_ACTION_READBACK');
 if(in_array($name,['n2-pending-public-read','n2-public-review-read'],true))m4uib_assert($publicListReads===1&&$publicConfigReads===1&&$wire['path']==='/api/reply/list/'.$state['review_item']['product_id'].'?page=1&limit=20&type=0'&&$wire['method']==='GET'&&($name!=='n2-pending-public-read'||$logoutSeen),'OBS_DISTINCT_SINGLE_PUBLIC_LIST_CONFIG_AND_PRIMARY_LIST_AFTER_NORMAL_LOGOUT');
 if($name==='n2-public-image-read'){
  m4uib_assert($wire['path']===$state['upload']['reference']&&$wire['method']==='GET','OBS_CASE20_EXACT_PUBLIC_IMAGE_WIRE');
  if($historical){m4uib_assert($publicImage===0,'OBS_NO_NEW_IMAGE_GET_IN_HISTORY_BRANCH');$w=$state['public_image_witness'];}
  else{m4uib_assert($publicImage===1&&is_array($newImageWitness),'OBS_ONE_REAL_CURRENT_IMAGE_RESPONSE');$w=$newImageWitness;}
  m4uws_image_dom($event['DOM'],$state,$before);$facts['public_image_read']=m4uws_image_fact($w,$historical);
 }
 if(!str_starts_with($name,'login-')){
  $customerRequests=false;foreach($event['requests']as$q)if($q['authentication']['present']===true&&$q['authentication']['subject_id']===$state['prepare']['actor_ids']['customer']&&str_starts_with($q['path'],'/api/'))$customerRequests=true;
  if($customerRequests)$rows=array_replace($rows,m4uwo_auth_touch_phase($before,$after,$event,$state['prepare']['actor_ids']['customer']));
  if($actor['app']==='adminapi')foreach(['c:all_auth','c:'.md5('Admin_rules_level__2_'.$actor['role_id'])]as$key){m4uib_assert(!isset($after['native_redis'][$key]),'OBS_SOURCE_PLATFORM_ONLY_EXACT_ROLE_AUTH_CACHE_DELETIONS');$redis[$key]=null;}
  // Complete combined native Redis verification follows for every case, including all logins.
 }
 m4uws_merge_native($before,$after,[['redis'=>$redis,'tags'=>$tags],$state['login_native_plan'],m4uws_cache_delta($before,$after,$startup)]);
 unset($state['login_native_plan']);m4uws_required($name,$event);
 usort($audits,static fn(array$a,array$b):int=>($a['sequence']??PHP_INT_MAX)<=>($b['sequence']??PHP_INT_MAX));m4uwo_audit_batch($before,$after,$audits,$source,$rows,$counts);
 m4ar_database_delta($before,$after,$rows,$counts);m4uwo_logs($before,$after,$event,$source);m4uwo_file_delta($before,$after,$changedRoots);
 $identity=m4uwo_plain($event['identity']);m4uib_keys($identity,['application','subject_id','merchant_id','verified_by']);
 if($role==='public')m4uib_assert(m4uib_same($identity,['application'=>'none','subject_id'=>null,'merchant_id'=>null,'verified_by'=>'actual-anonymous-normal-response']),'OBS_ACTUAL_PUBLIC_IDENTITY');
 else m4uib_assert($identity['application']===$actor['app']&&$identity['subject_id']===$id&&$identity['merchant_id']===($role==='merchant'?$state['prepare']['merchant_id']:null)&&$identity['verified_by']==='native-session-and-normal-UI','OBS_SAFE_NATIVE_ROLE_AND_NORMAL_UI_IDENTITY');
 if(!str_starts_with($name,'login-')&&$role!=='public')$state['sessions'][$role]['subsequent_UI_verified']=true;
 if($newImageWitness!==null)$state['public_image_witness']=$newImageWitness;
 $state['completed_cases'][]=$name;
 $safe=[];foreach($rows as$t=>$r)$safe[$t]=['projection'=>m4fn_projection($r),'natural_insert_count'=>$counts[$t]??0];
 return ['schema'=>2,'scope'=>'MS4_UI_INDEPENDENT_NORMAL_CASE_EXPECTATION','name'=>$name,'tables'=>(object)$safe,'independent'=>$facts,
  'all_other_database_redis_files_verified'=>true,'transient_ticket_native_observation'=>'NOT_CLAIMED','full_twenty_case_acceptance'=>false];
}


function m4uwo_finish_assert(array $state):void
{
 m4uib_assert($state['completed_cases']===m4uwo_steps(),'OBS_COMPLETE_EXACT_TWENTY_CASE_SEQUENCE');foreach(['customer','merchant','platform']as$role)
  m4uib_assert(($state['sessions'][$role]['signed_claims_verified']??false)===true&&($state['sessions'][$role]['subsequent_UI_verified']??false)===true,'OBS_ALL_THREE_SIGNED_NORMAL_LOGINS_AND_SUBSEQUENT_REAL_UI');
 $m=$state['model'];$summary=m4uwo_summary($m);$n1=array_values($m['refunds']);m4uib_assert(count($n1)===1&&$n1[0]['kind']==='money_only'&&$n1[0]['state']['business_state']==='APPROVED'
  &&$n1[0]['state']['physical_state']==='NONE'&&$n1[0]['state']['finance_state']==='UNSUPPORTED'&&$n1[0]['state']['held_amount']==='0.01'&&$n1[0]['state']['confirmed_amount']==='0.00','OBS_N1_APPROVED_HELD_NO_REAL_FINANCE');
 m4uib_assert($summary['original']===3&&$summary['shipped']===3&&$summary['received']===3&&$summary['child_count']===2&&$summary['closed']===true
  &&count($m['operations'])===5&&(int)$state['review']['status']===1&&(int)$state['review']['is_reply']===1&&$state['review']['merchant_reply_content']!==''
  &&isset($state['upload'])&&$state['upload']['file']['bytes']>0,'OBS_TWO_CHILD_FIVE_OPERATIONS_AND_PUBLIC_NONEMPTY_IMAGE_REVIEW');
}
function m4uwo_verify_case(string $name,array $before,array $after,array $input,array &$state,array $source):array
{return m4uwo_verify_twenty($name,$before,$after,$input,$state,$source);}


/** Only the one real producer's ordinary one-second sequence counter may naturally expire here. */
function m4uwo_sequence_barrier(string $name,array $event):?array
{
 if(!in_array($name,['n1-money-only-apply','ship-q2','ship-q1'],true))return null;$wire=$event['wire'];$number=$name==='n1-money-only-apply'?($wire['response_public']['refund']['refund_no']??null):($wire['response_public']['child_order_no']??null);
 m4uib_assert(is_string($number)&&preg_match('/\A(?:cp|cf)[1-9][0-9]{0,18}\z/D',$number)===1,'OBS_NORMAL_RESPONSE_REAL_SEQUENCE_NUMBER');$suffix=substr($number,2);$key=bcadd('1640966400000',bcdiv($suffix,'4194304',0),0);
 $sequence=bcmod($suffix,'4096',0);$started=hrtime(true);$first=m4uwo_redis();$others=$first['native'];$initial=$others[$key]??null;unset($others[$key]);$samples=[];$last=$first['time_ms'];
 if($initial!==null)m4uib_assert($initial['type']==='string'&&preg_match('/\A[1-9][0-9]*\z/D',$initial['value'])===1
  &&($sequence==='0'?$initial['value']==='1':$initial['value']===$sequence)&&$initial['expires']>=(int)$key+1000&&$initial['expires']<=(int)$last+1000,'OBS_ORIGINAL_NORMAL_SEQUENCE_COUNTER_VALUE_ONE_SECOND');
 $current=$first;
 do{m4uib_assert(hrtime(true)-$started<=10000000000&&bccomp($current['time_ms'],$last,0)>=0,'OBS_PASSIVE_NORMAL_COUNTER_BOUNDED_MONOTONIC');$last=$current['time_ms'];$now=$current['native'];$target=$now[$key]??null;unset($now[$key]);
  m4uib_assert($now===$others,'OBS_PASSIVE_COUNTER_EVERY_OTHER_NATIVE_DUMP_TYPE_VALUE_EXPIRY_EXACT');
  if($target===null)m4uib_assert(bccomp($last,(string)($initial===null?((int)$key+1000):$initial['expires']),0)>=0,'OBS_COUNTER_MISSING_ONLY_AFTER_ITS_SOURCE_NORMAL_DEADLINE');
  else m4uib_assert($initial!==null&&$target===$initial&&bccomp($last,(string)$initial['expires'],0)<0,'OBS_NORMAL_COUNTER_NO_REWRITE_OR_REAPPEARANCE');
  $samples[]=['time_ms'=>$last,'present'=>$target!==null,'other_native_sha256'=>hash('sha256',serialize($now))];if($target!==null){usleep(20000);$current=m4uwo_redis();}
 }while($target!==null);
 return ['schema'=>1,'scope'=>'PASSIVE_REAL_NORMAL_SEQUENCE_COUNTER_ONLY','number_sha256'=>hash('sha256',$number),'key_sha256'=>hash('sha256',$key),'initial_present'=>$initial!==null,
  'samples'=>$samples,'natural_final_absent'=>true,'elapsed_ns'=>hrtime(true)-$started];
}

function m4uwo_script_empty(array $snapshot):void
{
 $plan=['original'=>['config'=>$snapshot['rows']['eb_system_config']],'native_before'=>$snapshot,'config_reads'=>[],'config_stack'=>[]];
 // A real nonnull remember hit has priority; missing/null/false/trim-empty source values follow sys_config default ''.
 // Read-only native check: no app(), config/cache write, or projected response null.
 m4uib_assert(m4uws_config($plan,'statistic_script','')==='','OBS_NATIVE_EFFECTIVE_STATISTIC_SCRIPT_STRICTLY_EMPTY_BEFORE_ANY_NAVIGATION_OR_FILL');
}

/** The independently pinned complete source PNG has IHDR96x96 and exact234 bytes. */
function m4uwo_png_source():array
{return ['width'=>96,'height'=>96,'file'=>['bytes'=>234,'sha256'=>'1cb2b0cfb8e075b494268977c5f6360309edbc4e0800cd5c58399d291c5185b8']];}

/** UI-only independent DTO: the original AR donor and its1x1 normal assertions remain unchanged. */
function m4uwo_file_view(array $row):array
{
 $png=m4uwo_png_source();
 m4uib_assert($row['media_type']==='image/png'&&(int)$row['byte_size']===$png['file']['bytes']
  &&(int)$row['width']===$png['width']&&(int)$row['height']===$png['height']&&is_string($row['content_sha256'])
  &&hash_equals(hex2bin($png['file']['sha256']),$row['content_sha256']),'OBS_UI_FILE_VIEW_INDEPENDENT_NATIVE_ROW_AND_PINNED_PNG');
 return ['schema'=>1,'scope'=>'marketplace_aftersale_file_v1','id'=>(string)$row['id'],'root_order_id'=>(int)$row['root_order_id'],'media_type'=>'image/png',
  'byte_size'=>$png['file']['bytes'],'width'=>$png['width'],'height'=>$png['height'],'sha256'=>$png['file']['sha256'],
  'created_at'=>(int)$row['created_at'],'claim_expires_at'=>(int)$row['claim_expires_at']];
}

/** Independent finite normal config DTOs: original native DB plus real phase-before remember hits. */
function m4uws_original(array $snapshot):array
{
 $product=m4uib_file('/workspace/crmeb/app/services/product/product/StoreProductServices.php');m4uib_assert($product['descriptor']['bytes']===145261&&$product['descriptor']['sha256']==='cc4fbb0e962bb30c005bfc2ce2b9ab3caa6234b99c88634715aebe1e8af5e6e5','OBS_ACTUAL_ADOPTED_PRODUCT_SOURCE_SKU_PRESENCE_SEMANTICS');
 $names=['config'=>'eb_system_config','themes'=>'eb_theme','lang_type'=>'eb_lang_type','lang_country'=>'eb_lang_country','lang_code'=>'eb_lang_code'];$out=['rows'=>$snapshot['rows']];
 foreach($names as$key=>$table){m4uib_assert(array_key_exists($table,$snapshot['rows']),'OBS_BEGIN_COMPLETE_STARTUP_NATIVE_TABLE');$out[$key]=$snapshot['rows'][$table];}return $out;
}
function m4uws_plan(array $state,array $before):array
{return ['original'=>$state['startup_original'],'native_before'=>$before,'config_reads'=>[],'config_stack'=>[],'cache'=>[],'reads'=>[]];}

function m4uws_config_raw(array &$plan,string $name):mixed
{
 $rows=[];foreach($plan['original']['config']as$r)if($r['menu_name']===$name)$rows[]=$r;
 m4uib_assert(count($rows)<=1,'OBS_STARTUP_UNIQUE_OR_ABSENT_NATIVE_CONFIG');
 $value=$rows===[]||$rows[0]['value']===null?null:json_decode($rows[0]['value'],true);
 $hit=m4uws_native_cache($plan['native_before'],'system_config_'.$name);
 if($hit!==null)$value=$hit;
 if(isset($plan['config_reads'][$name]))m4uib_assert(m4uib_same($plan['config_reads'][$name]['value'],$value),'OBS_SOURCE_REPEATED_CONFIG_SAME_BEGIN_VALUE');
 $plan['config_reads'][$name]=['value'=>$value,'wire'=>m4tr_cache_wire($value)];return $value;
}
function m4uws_config(array &$plan,string $name,mixed $default=''):mixed
{
 m4uib_assert($name!==''&&!isset($plan['config_stack'][$name]),'OBS_NO_UNBOUNDED_CONFIG_URL_RECURSION');$plan['config_stack'][$name]=true;
 try{
  $value=m4uws_config_raw($plan,$name);
  if(is_array($value)){
   foreach($value as&$item)if(is_string($item)&&(str_contains($item,'/uploads/system/')||str_contains($item,'/statics/system_images/')))$item=m4uws_set_file($plan,$item);unset($item);
  }else{
   if($value===null)return $default;
   if(is_string($value)&&(str_contains($value,'/uploads/system/')||str_contains($value,'/statics/system_images/')))$value=m4uws_set_file($plan,$value);
  }
  $value=is_array($value)?$value:trim((string)$value);return $value===''||$value===false?$default:$value;
 }finally{unset($plan['config_stack'][$name]);}
}
function m4uws_set_file(array &$plan,mixed $image,string $site=''):mixed
{
 if(strlen(trim($site))===0)$site=m4uws_config($plan,'site_url');if(!$image)return $image;
 if(is_array($image)){foreach($image as&$item)if(substr($item,0,4)!=='http'&&substr($item,0,2)!=='//')$item=$site.str_replace('\\','/',$item);unset($item);return $image;}
 return substr($image,0,4)!=='http'&&substr($image,0,2)!=='//'?$site.str_replace('\\','/',$image):$image;
}
function m4uws_config_dto(array &$plan,string $path):mixed
{
 if($path==='/api/basic_config'){
  $out=[];foreach(['site_name','site_url','wap_login_logo','record_No','icp_url','network_security','network_security_url','store_self_mention','invoice_func_status','special_invoice_status','member_func_status','balance_func_status','recharge_switch','member_card_status','member_price_status']as$name)$out[$name]=m4uws_config($plan,$name);
  $out['ali_pay_status']=m4uws_config($plan,'ali_pay_status')!='0';$out['pay_weixin_open']=m4uws_config($plan,'pay_weixin_open')!='0';
  $out['yue_pay_status']=m4uws_config($plan,'yue_pay_status')==1&&m4uws_config($plan,'balance_func_status')!=0;
  $out['offline_pay_status']=m4uws_config($plan,'offline_pay_status')==1;$out['friend_pay_status']=m4uws_config($plan,'friend_pay_status')==1;
  $auth=m4uws_config($plan,'routine_auth_type');m4uib_assert(is_array($auth),'OBS_NORMAL_NATIVE_ROUTINE_AUTH_TYPE_ARRAY');
  $out['wechat_auth_switch']=(int)in_array(1,$auth);$auth=m4uws_config($plan,'routine_auth_type');$out['phone_auth_switch']=(int)in_array(2,$auth);
  $out['wechat_status']=m4uws_config($plan,'wechat_appid')!=''&&m4uws_config($plan,'wechat_appsecret')!='';
  $out['site_func']=m4uws_config($plan,'model_checkbox',['seckill','bargain','combination']);return $out;
 }
 if($path==='/api/copyright')return ['copyrightContext'=>m4uws_config($plan,'nncnL_crmeb_copyright',''),'copyrightImage'=>m4uws_config($plan,'nncnL_crmeb_copyright_image',''),'siteName'=>m4uws_config($plan,'site_name',''),'siteLogo'=>m4uws_config($plan,'wap_login_logo','')];
 if($path==='/api/share'){
  $image=m4uws_config($plan,'wechat_share_img');if(strstr($image,'http')===false&&$image!='')$image=m4uws_config($plan,'site_url').$image;
  return ['img'=>str_replace('\\','/',$image),'title'=>m4uws_config($plan,'wechat_share_title'),'synopsis'=>m4uws_config($plan,'wechat_share_synopsis')];
 }
 if($path==='/api/wechat/get_logo'){
  $logo=m4uws_config($plan,'wap_login_logo');if(strstr($logo,'http')===false&&$logo)$logo=m4uws_config($plan,'site_url').$logo;
  return ['logo_url'=>str_replace('\\','/',$logo)];
 }
 if($path==='/api/get_script'){$script=m4uws_config($plan,'statistic_script','');m4uib_assert($script==='','OBS_BEGIN_DERIVED_EMPTY_SCRIPT_ONLY');return '';}
 m4uib_assert(false,'OBS_NO_OTHER_UNMODELED_CONFIG_DTO');return null;
}
function m4uws_theme_row(array $original):array
{
 $found=[];foreach($original['themes']as$r)if((int)$r['is_use']===1)$found[]=$r;m4uib_assert(count($found)===1,'OBS_SOURCE_EXACT_ACTIVE_NATIVE_THEME');return $found[0];
}
function m4uws_theme(array $original,string $type):mixed
{
 $row=m4uws_theme_row($original);
 if($type==='theme'){$json=$row['theme_data'];if($json===''||$json===null||$json==='null')$json='{"theme_color":"#E93323","gradient_color":"#FF7931","sub_color":"#FE960F","light_color":"rgba(233, 51, 35, 0.1)"}';return json_decode($json,true)??[];}
 if($type==='navigation'){
  m4uib_assert((bool)$row['home_data'],'OBS_NORMAL_THEME_HOME_DATA_PRESENT');$home=json_decode($row['home_data'],true);m4uib_assert(is_array($home)&&is_array($home['value']??null),'OBS_NATIVE_THEME_HOME_NAVIGATION_SHAPE');
  foreach($home['value']as$item)if(isset($item['name'])&&strtolower($item['name'])==='pagefoot')return $item;return [];
 }
 m4uib_assert(false,'OBS_THEME_OTHER_TYPES_REQUIRE_COMPLETE_HYDRATION_SOURCE');return null;
}

/** Independent finite source remember plan. Native writes are produced only by normal UI HTTP. */
function m4uws_cache_call(array &$plan,string $name,mixed $value,int $seconds,array $request,bool $unorderedMap=false):void
{
 $physical='c:'.$name;$spec=['wire'=>m4tr_cache_wire($value),'seconds'=>$seconds,'interval'=>[$request['started_at_ms'],$request['finished_at_ms']],'unordered_map'=>$unorderedMap,'map'=>$unorderedMap?$value:null];
 if(isset($plan['cache'][$physical])){
  $old=$plan['cache'][$physical];m4uib_assert($old['seconds']===$seconds&&$old['unordered_map']===$unorderedMap&&($unorderedMap?m4uib_same($old['map'],$spec['map']):$old['wire']===$spec['wire']),'OBS_REPEATED_STARTUP_CACHE_ONE_SOURCE_VALUE');
  $spec['interval']=[min($old['interval'][0],$spec['interval'][0]),max($old['interval'][1],$spec['interval'][1])];
 }
 $plan['cache'][$physical]=$spec;
}
function m4uws_native_cache(array $snapshot,string $name):mixed
{
 $r=$snapshot['native_redis']['c:'.$name]??null;if($r===null)return null;
 m4uib_assert($r['type']==='string'&&is_string($r['value']),'OBS_STARTUP_NATIVE_CACHE_ORDINARY_STRING');$raw=$r['value'];
 if(is_numeric($raw))return $raw;$value=@unserialize($raw,['allowed_classes'=>false]);
 m4uib_assert(m4tr_cache_wire($value)===$raw,'OBS_STARTUP_NATIVE_CACHE_CANONICAL_NO_CLASSES');return $value;
}
function m4uws_cache_delta(array $before,array $after,array $plan):array
{
 $allowed=[];$members=$before['tag_members']['c:CRMEB:'.md5('crmeb')];
 foreach($plan['cache']as$key=>$spec){
  $old=$before['native_redis'][$key]??null;$now=$after['native_redis'][$key]??null;
  m4uib_assert(is_array($now)&&$now['type']==='string','OBS_FINITE_STARTUP_NATIVE_CACHE_TYPE');$wire=$spec['wire'];
  if($spec['unordered_map']){$actual=m4uws_native_cache($after,substr($key,2));m4uib_assert(is_array($actual)&&m4uib_same($actual,$spec['map']),'OBS_LANGUAGE_NO_ORDERBY_COMPLETE_BEGIN_MAP_VALUES_NO_RESPONSE_REFILL');$wire=m4tr_cache_wire($actual);}
  m4uib_assert($now['value']===$wire,'OBS_FINITE_STARTUP_CACHE_NATIVE_EXACT_VERIFIED_SOURCE_BYTES');
  if($spec['seconds']===0&&($old===null||$old['value']==='N;'))m4uib_assert($now['expires']===-1,'OBS_SOURCE_NEW_PERMANENT_REMEMBER');
  if($old!==null){m4uib_assert($old['type']==='string','OBS_SOURCE_EXISTING_REMEMBER_STRING');
   if($old['value']!=='N;')m4uib_assert($old['value']===$wire&&$now===$old,'OBS_REAL_REMEMBER_NONNULL_HIT_NO_VALUE_DEADLINE_REWRITE');
   // A real serialized null is a remember miss. Its independently derived callback value may replace N;.
  }
  if($old===null||$old['value']==='N;'){
   if($spec['seconds']===0)m4uib_assert($now['expires']===-1,'OBS_NORMAL_SOURCE_REMEMBER_PERMANENT');
   else m4uib_assert($now['expires']>=$spec['interval'][0]+$spec['seconds']*1000&&$now['expires']<=$spec['interval'][1]+$spec['seconds']*1000+1,'OBS_NORMAL_SOURCE_REMEMBER_REAL_REQUEST_BOUND_TTL');
  }
  m4uib_assert(!isset($before['native_redis'][$key.'_lock'])&&!isset($after['native_redis'][$key.'_lock']),'OBS_NORMAL_REMEMBER_LOCK_COMPLETE_NET_ABSENCE_NOT_INTERMEDIATE_PROOF');
  $allowed[$key]=$now;$members[]=$key;
 }
 $tags=[];
 if($plan['cache']!==[]){$members=array_values(array_unique($members,SORT_STRING));sort($members,SORT_STRING);$key='c:CRMEB:'.md5('crmeb');$tags[$key]=$members;}
 // Shared tag validation is deferred until source-proved session/upload/startup plans are merged.
 return ['redis'=>$allowed,'tags'=>$tags];
}
function m4uws_config_calls(array &$plan,array $request):void
{
 foreach($plan['config_reads']as$name=>$spec)m4uws_cache_call($plan,'system_config_'.$name,$spec['value'],0,$request);$plan['config_reads']=[];
}
function m4uws_uniqid(string $value,array $request):void
{
 m4uib_assert(preg_match('/\A[0-9a-f]{13}\z/D',$value)===1,'OBS_SOURCE_NORMAL_UNIQID13');$seconds=hexdec(substr($value,0,8));$micro=hexdec(substr($value,8,5));
 m4uib_assert($micro>=0&&$micro<1000000,'OBS_SOURCE_REAL_UNIQID_MICROSECONDS');$at=$seconds*1000000+$micro;
 m4uib_assert($at>=$request['started_at_ms']*1000&&$at<=($request['finished_at_ms']+1)*1000,'OBS_NEW_NORMAL_LANGUAGE_VERSION_REAL_REQUEST_CLOCK');
}
function m4uws_language_version(array &$plan,array $before,array $request):array
{
 $value=m4uws_native_cache($before,'lang_version');
 if($value===null){$value=m4uwo_plain($request['response_public']);m4uib_keys($value,['version']);m4uib_assert(is_string($value['version']),'OBS_NORMAL_NEW_LANGUAGE_UNIQID_RESPONSE_TYPE');m4uws_uniqid($value['version'],$request);}
 else{m4uib_assert(is_array($value),'OBS_BEGIN_ORIGINAL_LANGUAGE_VERSION_ARRAY');m4uib_keys($value,['version']);m4uib_assert(is_string($value['version'])&&preg_match('/\A[0-9a-f]{13}\z/D',$value['version'])===1,'OBS_BEGIN_ORIGINAL_LANGUAGE_UNIQID13');}
 m4uws_cache_call($plan,'lang_version',$value,0,$request);return $value;
}
function m4uws_language_list(array $original):array
{
 $out=[];foreach($original['lang_type']as$r)if((int)$r['status']===1&&(int)$r['is_del']===0)$out[]=['name'=>$r['language_name'],'value'=>$r['file_name']];return $out;
}
function m4uws_language_json(array &$plan,array $request):array
{
 $public=m4uwo_plain($request['request_public']);m4uib_keys($public,['headers']);m4uib_keys($public['headers'],['cb_lang','accept_language']);
 foreach($public['headers']as$v)m4uib_assert($v===null||(is_string($v)&&strlen($v)<=512&&!str_contains($v,"\0")&&!str_contains($v,"\r")&&!str_contains($v,"\n")),'OBS_ONLY_ACTUAL_SAFE_ORDINARY_LANGUAGE_HEADERS');
 $range=$public['headers']['cb_lang'];
 if(!$range){$defaults=[];foreach($plan['original']['lang_type']as$r)if((int)$r['is_default']===1)$defaults[]=$r;m4uib_assert(count($defaults)<=1,'OBS_UNAMBIGUOUS_NATIVE_DEFAULT_LANGUAGE');
  $range=$defaults[0]['file_name']??null;if(!$range)$range=$public['headers']['accept_language']!==null?explode(',',$public['headers']['accept_language'])[0]:'zh-CN';}
 m4uib_assert(is_string($range)&&$range!==''&&strlen($range)<=512,'OBS_SOURCE_NATIVE_OR_HEADER_LANGUAGE_RANGE');$countries=[];
 foreach($plan['original']['lang_country']as$r)if($r['code']===$range)$countries[]=$r;m4uib_assert(count($countries)<=1,'OBS_UNAMBIGUOUS_NATIVE_LANGUAGE_COUNTRY');$type=(int)($countries[0]['type_id']??0)?:1;
 $types=[];foreach($plan['original']['lang_type']as$r)if((int)$r['status']===1&&(int)$r['is_del']===0)$types[(int)$r['id']]=$r['file_name'];
 m4uib_assert(isset($types[$type])&&is_string($types[$type]),'OBS_SOURCE_ACTIVE_LANGUAGE_CACHE_SUFFIX');$data=[];
 foreach($plan['original']['lang_code']as$r)if((int)$r['type_id']===$type&&(int)$r['is_admin']===0){$code=(string)$r['code'];m4uib_assert(!array_key_exists($code,$data),'OBS_NATIVE_LANGUAGE_CODE_ONE_ROW_PER_KEY');$data[$code]=$r['lang_explain'];}
 $cacheName='api_lang_'.str_replace('-','_',$types[$type]);$hit=m4uws_native_cache($plan['native_before'],$cacheName);
 if($hit!==null){m4uib_assert(is_array($hit),'OBS_NATIVE_LANGUAGE_CACHE_COMPLETE_MAP');$data=$hit;}
 m4uws_cache_call($plan,$cacheName,$data,3600,$request,true);return [$range=>$data];
}

function m4uws_merge_native(array $before,array $after,array $plans):void
{
 $allowed=[];$tags=[];
 foreach($plans as$plan){
  foreach($plan['redis']as$key=>$entry){if(array_key_exists($key,$allowed))m4uib_assert($allowed[$key]===$entry,'OBS_NO_CONFLICTING_INDEPENDENT_NATIVE_DELTA');$allowed[$key]=$entry;}
  foreach($plan['tags']as$key=>$members){m4uib_assert(array_key_exists($key,$before['tag_members'])&&is_array($members),'OBS_ONLY_EXISTING_SOURCE_TAG_KINDS');
   $old=$before['tag_members'][$key];m4uib_assert(array_diff($old,$members)===[],'OBS_NORMAL_SOURCE_APPEND_DOES_NOT_DROP_OLD_TAG_MEMBER');$tags[$key]=array_merge($tags[$key]??$old,$members);}
 }
 foreach($tags as$key=>&$members){$members=array_values(array_unique($members,SORT_STRING));sort($members,SORT_STRING);$r=$after['native_redis'][$key]??null;
  m4uib_assert(is_array($r)&&$r['type']==='set'&&$r['expires']===-1&&$r['value']===$members,'OBS_UNIFIED_EXACT_SESSION_UPLOAD_STARTUP_TAG_UNION');$allowed[$key]=$r;}
 unset($members);m4uwo_redis_delta($before,$after,$allowed,$tags);
}

/** Ordinary cache reuse only in named case20; the earlier real response stays a RAM witness. */
function m4uws_image_wire(array $q):array
{
 return ['actor_role'=>'public','method'=>$q['method'],'path'=>$q['path'],'body_public'=>$q['request_public'],'body_public_sha256'=>$q['request_public_sha256'],
 'response_status'=>$q['http_status'],'response_public'=>$q['response_public'],'response_public_sha256'=>$q['response_public_sha256']];
}
function m4uws_image_witness(array $state,array $event,array $q):array
{
 m4uib_assert(isset($state['upload'])&&$q['method']==='GET'&&$q['path']===$state['upload']['reference']&&$q['http_status']===200
 &&m4uib_same($q['authentication'],['present'=>false,'kind'=>'none','subject_id'=>null]),'OBS_EARLIER_GENUINE_ANONYMOUS_IMAGE_RESPONSE');
 return ['binding'=>$state['binding'],'step_index'=>$event['index'],'step_name'=>$event['name'],'request_sequence'=>$q['sequence'],
 'wire_json'=>m4uwo_json(m4uws_image_wire($q)),'request'=>$q,'image'=>$state['upload']['file'],'reference'=>$state['upload']['reference'],
 'origin'=>$state['binding']['origin'],'private_response_sha256'=>$state['upload']['file']['sha256']];
}
function m4uws_image_reuse(array $state,array $wire):array
{
 $w=$state['public_image_witness']??null;
 m4uib_assert(is_array($w)&&m4uib_same($w['binding'],$state['binding'])&&$w['origin']===$state['binding']['origin']
 &&$w['reference']===$state['upload']['reference']&&m4uib_same($w['image'],$state['upload']['file'])&&$w['step_index']>=1&&$w['step_index']<20
 &&$w['wire_json']===m4uwo_json($wire)&&$w['private_response_sha256']===$state['upload']['file']['sha256'],'OBS_ONLY_SAME_RUN_SOURCE_IMAGE_PREVIOUS_COMPLETE_NORMAL_RESPONSE');return $w;
}
function m4uws_image_fact(array $w,bool $historical):array
{
 return ['mode'=>$historical?'historical-normal-response-reused':'fresh-normal-response','original_step'=>$w['step_index'],'original_name'=>$w['step_name'],
 'original_sequence'=>$w['request_sequence'],'original_wire_sha256'=>hash('sha256',$w['wire_json']),'image_sha256'=>$w['image']['sha256']];
}
function m4uws_image_dom(mixed $dom,array $state,array $before):void
{
 $d=m4uwo_plain($dom);m4uib_keys($d,['schema','scope','image_url','complete','natural_width','natural_height','visible','viewports']);
 $site=m4uwo_site($before);$ref=$state['upload']['reference'];$api=$site.$ref;
 // The actual API string can be relative. Resolve that normal path against the frozen page origin independently.
 if(str_starts_with($api,'//'))$url=parse_url($state['binding']['origin'],PHP_URL_SCHEME).':'.$api;
 elseif(preg_match('~\Ahttps?://~',$api)===1)$url=$api;
 else $url=$state['binding']['origin'].'/'.ltrim($api,'/');
 m4uib_assert($d['schema']===1&&$d['scope']==='MS4_UI_NORMAL_PUBLIC_IMAGE_DOM'&&$d['image_url']===$url&&$d['complete']===true&&$d['visible']===true
 &&$d['natural_width']===m4uwo_png_source()['width']&&$d['natural_height']===m4uwo_png_source()['height']&&is_array($d['viewports'])&&count($d['viewports'])===3,'OBS_CURRENT_DOM_SAME_SOURCE_IMAGE_NATIVE_NATURAL_DIMENSIONS');
 foreach([320,390,1440]as$i=>$width){$v=$d['viewports'][$i];m4uib_keys($v,['viewport_width','viewport_height','image_url','complete','natural_width','natural_height','visible','bounds','screenshot']);
  m4uib_assert($v['viewport_width']===$width&&(is_int($v['viewport_height'])||is_float($v['viewport_height']))&&is_finite((float)$v['viewport_height'])&&$v['viewport_height']>0
  &&$v['image_url']===$url&&$v['complete']===true&&$v['visible']===true&&$v['natural_width']===m4uwo_png_source()['width']&&$v['natural_height']===m4uwo_png_source()['height'],'OBS_CURRENT_THREE_REAL_CSS_VIEWPORT_IMAGE_FACTS');
  m4uib_keys($v['bounds'],['x','y','width','height']);foreach($v['bounds']as$n)m4uib_assert((is_int($n)||is_float($n))&&is_finite((float)$n),'OBS_REAL_FINITE_DOM_CSS_BOUNDS');$r=$v['bounds'];
  m4uib_assert($r['x']>=0&&$r['y']>=0&&$r['width']>0&&$r['height']>0&&$r['x']+$r['width']<=$width&&$r['y']+$r['height']<=$v['viewport_height'],'OBS_COMPLETE_IMAGE_VISIBLE_INSIDE_ACTUAL_CSS_VIEWPORT');
  $f=$v['screenshot'];m4uib_keys($f,['path','bytes','sha256']);m4uib_assert(is_string($f['path'])&&strlen($f['path'])<=512
  &&preg_match('~\A[A-Za-z0-9][A-Za-z0-9._/-]*\.png\z~D',$f['path'])===1&&!str_contains($f['path'],'..')&&!str_contains($f['path'],'//')
  &&is_int($f['bytes'])&&$f['bytes']>0&&$f['bytes']<=67108864&&m4uib_hex($f['sha256']),'OBS_SAME_BOUND_EVENT_SAFE_HOST_SCREENSHOT_DESCRIPTOR_ONLY');
 }
 // This PHP process does not open Host screenshot paths. Root genuine DOM producer and independent Host consumers
 // must verify real capture time/source/tab/binding plus complete PNG bytes/hash/IHDR; physical pixels are not CSS viewport pixels.
}

/** Independent ordinary configuration hydration, derived only from original native rows and real remember hits. */
function m4uws_rows(array $plan,string $table):array
{m4uib_assert(array_key_exists($table,$plan['original']['rows']),'OBS_COMPLETE_BEGIN_TABLE_FOR_NORMAL_READ');$rows=$plan['original']['rows'][$table];
 if($table==='eb_store_product_reply'&&($plan['current_review']??null)!==null){$r=$plan['current_review'];$seen=false;foreach($rows as&$old)if((string)$old['id']===(string)$r['id']){$old=$r;$seen=true;}unset($old);if(!$seen)$rows[]=$r;}
 if($table==='eb_system_attachment'&&($plan['current_upload']??null)!==null){$r=$plan['current_upload']['row'];$seen=false;foreach($rows as&$old)if((string)$old['att_id']===(string)$r['att_id']){$old=$r;$seen=true;}unset($old);if(!$seen)$rows[]=$r;}return $rows;}
function m4uws_vip(array &$plan,bool $productVip):bool
{
 $open=m4uws_config($plan,'member_card_status',1);$rights=[];foreach(m4uws_rows($plan,'eb_member_right')as$r)if($r['right_type']==='vip_price')$rights[]=$r;
 m4uib_assert(count($rights)<=1,'OBS_NATIVE_UNIQUE_MEMBER_RIGHT_VALUE');$right=$rights[0]??null;$status=$open&&$right&&$right['status']&&$right['number']?$right['number']:false;
 return $status&&m4uws_config($plan,'member_card_status')&&$productVip&&m4uws_config($plan,'member_price_status',1);
}
function m4uws_root(array $plan,int $id):?array
{
 m4uib_assert(getenv('PHP_MARKETPLACE_ENABLED')==='true'&&getenv('PHP_MARKETPLACE_TRADE_ENABLED')==='true','OBS_ACTUAL_ALREADY_GATED_MARKETPLACE_ENV');
 $self=getenv('PHP_MARKETPLACE_SELF_OPERATED_MER_ID');m4uib_assert(is_string($self)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$self)===1,'OBS_ACTUAL_CONFIGURED_SELF_NOT_PRODUCT_GUESS');
 $merchants=[];foreach(m4uws_rows($plan,'eb_marketplace_merchant')as$r)$merchants[(int)$r['mer_id']]=$r;m4uib_assert(isset($merchants[(int)$self]),'OBS_NATIVE_CONFIGURED_SELF_EXISTS');
 foreach(['eb_store_product','eb_system_attachment','eb_system_attachment_category','eb_shipping_templates','eb_store_product_rule']as$t)
  foreach(m4uws_rows($plan,$t)as$r)m4uib_assert($r['mer_id']!==null&&(int)$r['mer_id']>0&&isset($merchants[(int)$r['mer_id']]),'OBS_COMPLETE_ORIGINAL_OWNERSHIP_NAMESPACE');
 $found=[];foreach(m4uws_rows($plan,'eb_store_product')as$r)if((int)$r['id']===$id)$found[]=$r;m4uib_assert(count($found)<=1,'OBS_NATIVE_UNIQUE_PRODUCT_ID');if($found===[])return null;$r=$found[0];
 $m=$merchants[(int)$r['mer_id']];if((int)$r['review_status']!==2||(int)$r['is_show']!==1||(int)$r['is_del']!==0||(int)$m['review_status']!==1||(int)$m['security_locked']!==0||$m['closed_at']!==null)return null;
 foreach(m4uws_rows($plan,'eb_store_advance')as$a)if((int)$a['product_id']===$id&&(int)$a['is_del']===0)return null;return $r;
}
function m4uws_labels(array $plan,mixed $value):array
{
 if($value===null||$value==='')return [];if(is_string($value)){$ids=explode(',',$value);$out=[];foreach(m4uws_rows($plan,'eb_store_product_label')as$r)
  if(in_array((int)$r['id'],array_map('intval',$ids),true)&&(int)$r['status']===1&&(int)$r['is_show']===1)$out[]=array_intersect_key($r,array_fill_keys(['name','font_color','bg_color','border_color','image'],true));return $out;}
 m4uib_assert(is_array($value)&&array_is_list($value),'OBS_NATIVE_LABEL_FINAL_LIST');$out=[];foreach($value as$r){m4uib_assert(is_array($r),'OBS_NATIVE_LABEL_ROW');$out[]=array_intersect_key($r,array_fill_keys(['name','font_color','bg_color','border_color','image'],true));}return $out;
}
function m4uws_cart_product_quantity(array $plan,int $uid,int $product):int
{
 if(!$uid)return 0;$groups=[];foreach(m4uws_rows($plan,'eb_store_cart')as$r)if((int)$r['uid']===$uid&&(int)$r['product_id']===$product&&(int)$r['is_pay']===0&&(int)$r['is_del']===0&&(int)$r['is_new']===0){
  $key=(string)$r['product_attr_unique'];$projection=['cart_num'=>$r['cart_num'],'product_id'=>$r['product_id']];
  if(isset($groups[$key]))m4uib_assert($groups[$key]===$projection,'OBS_ORIGINAL_CART_GROUP_PROJECTION_UNAMBIGUOUS');$groups[$key]=$projection;}
 return array_sum(array_column($groups,'cart_num'));
}
function m4uws_product(array &$plan,array $r,int $uid,bool $extras=true):array
{
 $id=(int)$r['id'];if(!m4uws_vip($plan,(bool)$r['is_vip']))$r['vip_price']=0;
 m4uib_assert(!json_decode((string)($r['custom_form']??''),true),'OBS_NATIVE_NONEMPTY_CUSTOM_FORM_REQUIRES_ACTUAL_V1_CREATE_POLICY_SOURCE');
 $r['cart_button']=$r['is_virtual']||$r['virtual_type']==3||$r['presale']?0:1;
 $r['slider_image']=is_string($r['slider_image'])?json_decode($r['slider_image'],true):[];
 if(isset($r['label_list'])&&is_string($r['label_list']))$r['label_list']=m4uws_labels($plan,$r['label_list']);
 $activity=$r['activity']?:'0,1,2,3';
 // The source short-circuits all activity-ID queries for output when the first configured priority is zero.
 m4uib_assert(explode(',',$activity)[0]==0,'OBS_NONZERO_ACTIVITY_PRIORITY_NEEDS_ITS_PRECISE_SOURCE_MODEL');$r['activity']=[];
 $r['checkCoupon']=false;foreach(m4uws_rows($plan,'eb_store_coupon_issue')as$c)if(in_array((string)$id,explode(',',(string)$c['product_id']),true)){$r['checkCoupon']=true;break;}
 $stock=0;$present=false;foreach(m4uws_rows($plan,'eb_store_product_attr_value')as$v)if((int)$v['product_id']===$id&&(int)$v['type']===0&&(int)$v['is_show']===1){$stock+=(int)$v['stock'];$present=true;}
 if($extras){$r['is_att']=$r['spec_type']?$present:false;$r['cart_num']=m4uws_cart_product_quantity($plan,$uid,$id);}
 if(isset($r['is_vip'],$r['vip_price'])&&!m4uws_vip($plan,(bool)$r['is_vip']))$r['vip_price']=0;
 $fields=['id','mer_id','store_name','store_info','image','slider_image','small_image','image_base','price','ot_price','vip_price','unit_name','sales','fsales','stock','spec_type','is_postage','postage','is_vip','video_link','min_qty','limit_num','is_virtual','virtual_type','presale','presale_start_time','presale_end_time','presale_day','presale_pay_status','cart_button','cart_num','is_att','labels','label_list','activity','star','checkCoupon','cate_name'];
 $out=array_intersect_key($r,array_fill_keys($fields,true));$out['id']=$id;$out['mer_id']=(int)$r['mer_id'];$out['stock']=$stock;m4uib_assert($stock>=0&&$stock<=16777215,'OBS_NATIVE_PUBLIC_ACTIVE_SKU_STOCK_BOUNDS');
 foreach(['labels','label_list']as$k)if(array_key_exists($k,$out))$out[$k]=m4uws_labels($plan,$out[$k]);
 if(array_key_exists('star',$out))m4uib_assert(is_string($out['star'])&&preg_match('/\A-?(?:0|[1-9][0-9]*)\.[0-9]\z/D',$out['star'])===1,'OBS_SOURCE_FINAL_PRODUCT_STAR_TYPE');return $out;
}
function m4uws_saved_products(array &$plan,array $saved,int $uid):array
{
 $ids=[];foreach($saved as$r){if(!is_array($r)||!isset($r['id']))continue;$id=$r['id'];if(is_string($id)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$id)===1)$id=(int)$id;
 m4uib_assert(is_int($id)&&$id>0&&$id<=8388607,'OBS_NATIVE_SAVED_CONFIGURATION_POSITIVE_PRODUCT_ID');$ids[]=$id;}
 $by=[];foreach(array_unique($ids)as$id){$r=m4uws_root($plan,$id);if($r!==null)$by[$id]=m4uws_product($plan,$r,$uid);}
 $out=[];foreach($ids as$id)if(isset($by[$id]))$out[]=$by[$id];return $out;
}
function m4uws_component(array &$plan,array $c,int $uid):array
{
 if(!in_array($c['name']??'', ['goodList','promotionList'],true))return $c;
 foreach(['goodsList','productList']as$slot)if(is_array($c[$slot]['list']??null))$c[$slot]['list']=m4uws_saved_products($plan,$c[$slot]['list'],$uid);
 if(($c['name']??'')==='promotionList'&&is_array($c['tabConfig']['list']??null))foreach($c['tabConfig']['list']as&$tab)if(is_array($tab)&&is_array($tab['goodsList']['list']??null))$tab['goodsList']['list']=m4uws_saved_products($plan,$tab['goodsList']['list'],$uid);unset($tab);return $c;
}
function m4uws_home_dto(array &$plan,int $uid):array
{
 $row=m4uws_theme_row($plan['original']);$home=json_decode($row['home_data'],true)??[];m4uib_assert(is_array($home),'OBS_NATIVE_HOME_CONFIGURATION_ARRAY');
 if($home){m4uib_assert(is_array($home['value']??null),'OBS_NATIVE_HOME_COMPONENT_ARRAY');foreach($home['value']as&$c){m4uib_assert(is_array($c),'OBS_NATIVE_HOME_COMPONENT_RECORD');if(($c['name']??'')==='customerService')$c['routine_contact_type']=(int)m4uws_config($plan,'routine_contact_type');}unset($c);}
 if(isset($home['value'])&&is_array($home['value'])){foreach($home['value']as&$c)if(is_array($c))$c=m4uws_component($plan,$c,$uid);unset($c);return $home;}
 foreach($home as$key=>&$cs){if(!is_array($cs))continue;if(isset($cs['name']))$cs=m4uws_component($plan,$cs,$uid);
 elseif(in_array($key,['goodList','promotionList'],true))foreach($cs as&$c){if(!is_array($c))continue;$name=$c['name']??null;if($name===null)$c['name']=$key;$c=m4uws_component($plan,$c,$uid);if($name===null)unset($c['name']);}unset($c);}unset($cs);return $home;
}
function m4uws_unordered(array $rows):array
{$out=[];foreach($rows as$r)$out[]=m4uwo_json($r);sort($out,SORT_STRING);return $out;}
function m4uws_normalize_product_labels(array $products):array
{
 foreach($products as&$r)if(is_array($r))foreach(['label_list','labels']as$k)if(is_array($r[$k]??null)){$v=$r[$k];usort($v,static fn($a,$b):int=>strcmp(m4uwo_json($a),m4uwo_json($b)));$r[$k]=$v;}unset($r);return $products;
}
function m4uws_normalize_home_labels(array $home):array
{
 $fix=static function(array $c):array{if(!in_array($c['name']??'',['goodList','promotionList'],true))return $c;
 foreach(['goodsList','productList']as$slot)if(is_array($c[$slot]['list']??null))$c[$slot]['list']=m4uws_normalize_product_labels($c[$slot]['list']);
 if(is_array($c['tabConfig']['list']??null))foreach($c['tabConfig']['list']as&$t)if(is_array($t['goodsList']['list']??null))$t['goodsList']['list']=m4uws_normalize_product_labels($t['goodsList']['list']);unset($t);return $c;};
 if(isset($home['value'])&&is_array($home['value'])){foreach($home['value']as&$c)if(is_array($c))$c=$fix($c);unset($c);return $home;}
 foreach($home as$key=>&$cs){if(!is_array($cs))continue;if(isset($cs['name']))$cs=$fix($cs);elseif(in_array($key,['goodList','promotionList'],true))foreach($cs as&$c){if(!is_array($c))continue;$name=$c['name']??null;if($name===null)$c['name']=$key;$c=$fix($c);if($name===null)unset($c['name']);}unset($c);}unset($cs);return $home;
}

/** Normal home cart/coupon producers: exact source predicates over complete original native data. */
function m4uws_cart_sku(array $plan,array $cart):?array
{
 m4uib_assert((int)$cart['type']===0,'OBS_NONORDINARY_EXISTING_CART_NEEDS_ITS_NATIVE_LINEAGE_SOURCE_MODEL');
 $root=m4uws_root($plan,(int)$cart['product_id']);if($root===null)return null;$unique=$cart['product_attr_unique'];
 if(!is_string($unique)||preg_match('/\A[a-zA-Z0-9]{8}\z/D',$unique)!==1)return null;
 $map=[];$identities=[];$stock=0;foreach(m4uws_rows($plan,'eb_store_product_attr_value')as$v)if((int)$v['product_id']===(int)$root['id']&&(int)$v['type']===0&&(int)$v['is_show']===1){
  m4uib_assert((int)$v['id']>0&&is_string($v['unique'])&&preg_match('/\A[a-zA-Z0-9]{8}\z/D',$v['unique'])===1&&is_string($v['suk'])&&$v['suk']!==''&&!isset($map[$v['suk']])&&!isset($identities[$v['unique']]),'OBS_INDEPENDENT_COMPLETE_NATIVE_SKU_IDENTITIES');
  m4uib_assert((int)$v['stock']>=0&&(int)$v['stock']<=16777215,'OBS_NATIVE_SKU_STOCK_VALID');$stock+=(int)$v['stock'];$map[$v['suk']]=$v;$identities[$v['unique']]=true;}
 m4uib_assert($stock<=16777215,'OBS_NATIVE_TOTAL_SKU_STOCK_VALID');
 if((int)$root['spec_type']===0){if(count($map)!==1)return null;}
 else{$axes=[];$names=[];foreach(m4uws_rows($plan,'eb_store_product_attr')as$a)if((int)$a['product_id']===(int)$root['id']&&(int)$a['type']===0)$axes[]=$a;
  usort($axes,static fn($a,$b):int=>(int)$a['id']<=>(int)$b['id']);$options=[];foreach($axes as$a){$name=$a['attr_name'];if(!is_string($name)||$name===''||isset($names[$name])||!is_string($a['attr_values']))return null;$names[$name]=true;$o=explode(',',$a['attr_values']);if(in_array('',$o,true)||count(array_unique($o,SORT_STRING))!==count($o))return null;$options[]=$o;}
  if(!$axes||!$map)return null;foreach($map as$key=>$v){$parts=explode(',',$key);if(count($parts)!==count($options))return null;foreach($parts as$i=>$part)if(!in_array($part,$options[$i],true))return null;}}
 $found=[];foreach($map as$v)if($v['unique']===$unique)$found[]=$v;if($found===[])return null;m4uib_assert(count($found)===1,'OBS_ONE_VISIBLE_CART_SKU');$sku=$found[0];
 // The real cart hasOne relation looks up unique globally; qualify its cached id independently as well.
 $cached=[];foreach(m4uws_rows($plan,'eb_store_product_attr_value')as$v)if($v['unique']===$unique)$cached[]=$v;m4uib_assert(count($cached)<=1,'OBS_UNAMBIGUOUS_ORIGINAL_CART_HASONE_UNIQUE');
 if($cached!==[]&&(int)$cached[0]['id']!==(int)$sku['id'])return null;return ['root'=>$root,'sku'=>$sku];
}
function m4uws_cart_count(array &$plan,int $uid,array $response):void
{
 m4uib_assert($uid>0,'OBS_NORMAL_HOME_CART_ACTUAL_LOGGED_CUSTOMER');$carts=[];foreach(m4uws_rows($plan,'eb_store_cart')as$r)if((int)$r['uid']===$uid&&(int)$r['is_pay']===0&&(int)$r['is_new']===0&&(int)$r['is_del']===0)$carts[]=$r;
 usort($carts,static fn($a,$b):int=>(int)$b['add_time']<=>(int)$a['add_time']);$sum=0;$count=array_sum(array_column($carts,'cart_num'));
 if($carts){$open=m4uws_config($plan,'member_card_status',1);$rights=[];foreach(m4uws_rows($plan,'eb_member_right')as$r)if($r['right_type']==='vip_price')$rights[]=$r;m4uib_assert(count($rights)<=1,'OBS_NORMAL_CART_UNIQUE_MEMBER_RIGHT');$right=$rights[0]??null;$vip=(bool)($open&&$right&&$right['status']);
  $user=m4ar_one(m4uws_rows($plan,'eb_user'),'uid',$uid);$discount=100;
  if(m4uws_config($plan,'member_func_status',1)){$levels=[];foreach(m4uws_rows($plan,'eb_system_user_level')as$r)if((int)$r['id']===(int)$user['level']&&(int)$r['is_del']===0&&(int)$r['is_show']===1)$levels[]=$r;m4uib_assert(count($levels)<=1,'OBS_NATIVE_UNIQUE_USER_LEVEL');$discount=$levels===[]?100:($levels[0]['discount']?:100);}
  $factor=bcdiv((string)$discount,'100',2);
  foreach($carts as$c){$identity=m4uws_cart_sku($plan,$c);if($identity===null)continue;$root=$identity['root'];$sku=$identity['sku'];$price=$sku['price']??0;$vipPrice=$sku['vip_price']??0;$isVip=$root['is_vip']??0;
   if(!(float)$price)$true=0;
   else{if(!$vip)$isVip=0;if($isVip==0)$vipPrice=0;$level=$factor&&$factor!=0.00?bcmul($factor,(string)$price,2):$price;$member=$vipPrice<$level&&$vipPrice>0?$vipPrice:$level;
    $true=$vip&&$isVip==1&&isset($user['is_money_level'])&&$user['is_money_level']>0?$member:$level;$true=(float)($true<0.01?0.01:$true);}
   $sum=bcadd((string)$sum,(string)bcmul((string)$c['cart_num'],(string)$true,4),2);
  }
 }
 m4uib_keys($response,['count','ids','sum_price']);m4uib_assert($response['count']===$count&&$response['sum_price']===$sum&&is_array($response['ids'])&&count($response['ids'])===count($carts),'OBS_FULL_NATIVE_NORMAL_HOME_CART_COUNT_SUM');
 // SQL orders only add_time DESC. Preserve each time group, allowing only its genuinely unspecified tie order.
 $groups=[];foreach($carts as$c)$groups[(string)$c['add_time']][]=(string)$c['id'];$offset=0;
 foreach($groups as$ids){$actual=array_slice($response['ids'],$offset,count($ids));foreach($actual as$id)m4uib_assert(is_string($id),'OBS_ACTUAL_CART_MODEL_FORCES_BIGINT_IDS_TO_STRING');sort($ids,SORT_STRING);sort($actual,SORT_STRING);m4uib_assert($ids===$actual,'OBS_CART_COMPLETE_IDS_AND_NATIVE_ORDER_GROUP');$offset+=count($ids);}
}
function m4uws_coupon_times(array $plan,array $request):array
{
 $lo=intdiv($request['started_at_ms'],1000);$hi=intdiv($request['finished_at_ms'],1000);$times=[$lo,$hi];
 foreach(m4uws_rows($plan,'eb_store_coupon_issue')as$r)foreach(['start_time','end_time']as$k)foreach([(int)$r[$k]-1,(int)$r[$k],(int)$r[$k]+1]as$t)if($t>=$lo&&$t<=$hi)$times[]=$t;
 $day=strtotime(date('Y-m-d',$lo).' +1 day');while($day<=$hi){$times[]=$day-1;$times[]=$day;$day=strtotime('+1 day',$day);} $times=array_values(array_unique($times,SORT_NUMERIC));sort($times,SORT_NUMERIC);return $times;
}
function m4uws_coupon_list(array $plan,int $uid,bool $today,int $startClock,int $endClock,int $dayClock):array
{
 $list=[];$day=strtotime(date('Y-m-d',$dayClock));$next=strtotime('+1 day',$day);
 foreach(m4uws_rows($plan,'eb_store_coupon_issue')as$r){
  if((int)$r['status']!==1||(int)$r['is_del']!==0||!((int)$r['remain_count']>0||(int)$r['is_permanent']===1))continue;
  if($today?!in_array((int)$r['receive_type'],[1,4],true):(int)$r['receive_type']!==2)continue;
  if(!(((int)$r['start_time']<$startClock&&(int)$r['end_time']>$endClock)||((int)$r['start_time']===0&&(int)$r['end_time']===0)))continue;
  if($today&&((int)$r['add_time']<$day||(int)$r['add_time']>$next-1))continue;
  if($today&&$uid!==0){$owned=false;foreach(m4uws_rows($plan,'eb_store_coupon_issue_user')as$u)if((int)$u['uid']===$uid&&(int)$u['issue_coupon_id']===(int)$r['id']){$owned=true;break;}if($owned)continue;$r['used']=[];}
  $r['start_time']=$r['start_time']?date('Y/m/d',(int)$r['start_time']):0;$r['end_time']=$r['end_time']?date('Y/m/d',(int)$r['end_time']):0;
  $r['coupon_price']=(float)$r['coupon_price'];$r['use_min_price']=(float)$r['use_min_price'];$list[]=$r;
 }
 if($today)usort($list,static fn($a,$b):int=>((int)$b['sort']<=>(int)$a['sort'])?:((int)$b['id']<=>(int)$a['id']));return $list;
}
function m4uws_coupon_show(array $before,array $event,array $q,int $uid):array
{
 $u=m4ar_one($before['rows']['eb_user'],'uid',$uid);$at=$u['add_time'];$possible=[];$guaranteed=false;$clocks=[];
 foreach($event['requests']as$r){if($r['sequence']===$q['sequence']||$r['started_at_ms']>$q['finished_at_ms'])continue;
  $writes=($r['authentication']['present']===true&&$r['authentication']['subject_id']===$uid&&str_starts_with($r['path'],'/api/')&&$r['path']!=='/api/get_script')||($r['path']==='/api/login'&&$r['method']==='POST');if(!$writes)continue;
  if($r['finished_at_ms']<=$q['started_at_ms'])$guaranteed=true;$clocks[]=m4uwo_interval($r);
 }
 if(!$guaranteed)$possible[]=$u['add_time']===$u['last_time']?1:0;
 foreach($clocks as[$a,$b]){$b=min($b,intdiv($q['finished_at_ms'],1000));if($a>$b)continue;if(is_int($at)&&$at>=$a&&$at<=$b)$possible[]=1;if(!is_int($at)||$a!==$at||$b!==$at)$possible[]=0;}
 m4uib_assert($possible!==[],'OBS_NATIVE_BEFORE_AND_REAL_PRIOR_REQUEST_USER_OBJECT_TIME_POSSIBILITIES');return array_values(array_unique($possible,SORT_NUMERIC));
}
function m4uws_coupon_read(array &$plan,array $before,array $event,array $q,int $uid):void
{
 $today=$q['path']==='/api/v2/get_today_coupon';$actual=m4uwo_plain($q['response_public']);m4uib_keys($actual,$today?['list','image']:['list','image','show']);
 m4uib_assert(is_array($actual['list'])&&$actual['image']===m4uws_config($plan,'coupon_img'),'OBS_INDEPENDENT_NORMAL_COUPON_IMAGE_AND_LIST_TYPE');$match=false;$times=m4uws_coupon_times($plan,$q);
 foreach($times as$a)foreach($times as$b)if($a<=$b)foreach($times as$c)if($b<=$c){$expected=m4uws_coupon_list($plan,$uid,$today,$a,$b,$c);
  if($today?m4uib_same($actual['list'],$expected):m4uws_unordered($actual['list'])===m4uws_unordered($expected)){$match=true;break 3;}}
 m4uib_assert($match,'OBS_COMPLETE_COUPON_SOURCE_PREDICATES_AND_NATIVE_REQUEST_CLOCK_FINITE_RESULT');
 if(!$today)m4uib_assert($uid>0&&in_array($actual['show'],m4uws_coupon_show($before,$event,$q,$uid),true),'OBS_NEW_COUPON_ORIGINAL_AUTH_USER_READ_PRECEDES_ITS_LAST_TIME_UPDATE');
}

/** Ordinary goodList mounted GET products. Query options must be derived from actual original visible home components. */
function m4uws_js_truthy(mixed $v):bool
{if($v===null||$v===false||$v===''||(is_int($v)&&$v===0)||(is_float($v)&&$v==0.0))return false;return true;}
function m4uws_good_query(array $config):?array
{
 $type=(int)($config['typeConfig']['activeValue']??0);$sort=(int)($config['goodsSort']['tabVal']??0);
 if($type===1){$ids=[];foreach(($config['goodsList']['list']??[])as$r)if(m4uws_js_truthy($r['id']??null))$ids[]=(string)$r['id'];return $ids?['ids'=>implode(',',$ids)]:null;}
 if($type===3){$values=$config['classList']['classVal']??[];m4uib_assert(is_array($values),'OBS_NATIVE_GOODLIST_CATEGORY_ARRAY');return ['priceOrder'=>$sort===2?'desc':'','salesOrder'=>$sort===1?'desc':'','cate_id'=>implode(',',$values),'limit'=>(string)$config['numberConfig']['val']];}
 if($type===4){$values=$config['goodsLabel']['activeValue']??[];m4uib_assert(is_array($values),'OBS_NATIVE_GOODLIST_LABEL_ARRAY');return ['priceOrder'=>$sort===2?'desc':'','salesOrder'=>$sort===1?'desc':'','store_label_id'=>implode(',',$values),'limit'=>(string)$config['numberConfig']['val']];}
 return [];
}
function m4uws_home_queries(array $plan,int $uid):array
{
 $derivation=$plan;$home=m4uws_home_dto($derivation,$uid);$queries=[];
 foreach(($home['value']??[])as$c){if(!is_array($c)||m4uws_js_truthy($c['isHide']??null))continue;$name=$c['name']??'';
  if($name==='goodList'||$name==='goodRecommend'){$q=m4uws_good_query($c);if($q!==null)$queries[]=$q;}
  if($name==='promotionList'){$tabs=$c['tabConfig']['list']??[];m4uib_assert(is_array($tabs)&&isset($tabs[0]),'OBS_NATIVE_PROMOTION_FIRST_ACTIVE_TAB');$t=$tabs[0];$type=(int)$t['tabVal'];if($type===0)$type=3;
   $config=['goodsList'=>$t['goodsList'],'classList'=>['classVal'=>$t['selectConfig']['activeValue']],'goodsLabel'=>$t['goodsLabel'],'typeConfig'=>['activeValue'=>$type],'goodsSort'=>['tabVal'=>$t['goodsSort']],'numberConfig'=>['val'=>$tabs[0]['numConfig']['val']]];
   $q=m4uws_good_query($config);if($q!==null)$queries[]=$q;
  }
 }return $queries;
}
function m4uws_product_query(array $plan,array $request,int $uid):array
{
 $path=$request['path'];$raw=parse_url($path,PHP_URL_QUERY)??'';m4uib_assert(parse_url($path,PHP_URL_PATH)==='/api/products','OBS_EXACT_NORMAL_PRODUCT_LIST_ENDPOINT');$query=[];
 if($raw!=='')foreach(explode('&',$raw)as$pair){$parts=explode('=',$pair,2);m4uib_assert(count($parts)===2,'OBS_ACTUAL_NORMAL_GET_QUERY_PAIR');$key=urldecode($parts[0]);$value=urldecode($parts[1]);m4uib_assert(!array_key_exists($key,$query)&&!str_contains($key,'[')&&!str_contains($value,"\0"),'OBS_UNIQUE_ORDINARY_QUERY_SCALARS');$query[$key]=$value;}
 $match=false;foreach(m4uws_home_queries($plan,$uid)as$source)if(m4uib_same($query,$source)){$match=true;break;}m4uib_assert($match,'OBS_EXACT_QUERY_FROM_VISIBLE_ORIGINAL_NORMAL_COMPONENT_NOT_ARBITRARY_PRODUCTS_ALLOWANCE');return $query;
}
function m4uws_products_read(array &$plan,array $q,int $uid):void
{
 $query=m4uws_product_query($plan,$q,$uid);$ids=isset($query['ids'])?array_values(array_filter(array_map('intval',explode(',',$query['ids'])),static fn($v):bool=>$v!==0)):null;
 $cats=($query['cate_id']??'')!==''?array_map('intval',explode(',',$query['cate_id'])):[];$labels=($query['store_label_id']??'')!==''?array_map('intval',explode(',',$query['store_label_id'])):[];
 $price=$query['priceOrder']??'';$sales=$query['salesOrder']??'';$rows=[];$viewer=$uid?m4ar_one(m4uws_rows($plan,'eb_user'),'uid',$uid)['is_money_level']:0;
 foreach(m4uws_rows($plan,'eb_store_product')as$raw){$id=(int)$raw['id'];$root=m4uws_root($plan,$id);if($root===null)continue;
  $category=true;if($cats){$category=false;foreach(m4uws_rows($plan,'eb_store_product_cate')as$c)if((int)$c['product_id']===$id&&(in_array((int)$c['cate_id'],$cats,true)||in_array((int)$c['cate_pid'],$cats,true))){$category=true;break;}}
  $label=true;if($labels){$label=false;foreach($labels as$l)if(in_array((string)$l,explode(',',(string)$raw['label_list']),true)){$label=true;break;}}
  $inside=($ids===null||in_array($id,$ids,true))&&$category&&$label;
  // Real withSearch directly mutates one group. Preserve this existing VIP OR, then the real outer id/label/publication constraints.
  if($viewer===0)$inside=($inside&&(int)$raw['vip_product']===0)||((int)$raw['vip_product']===1&&(int)$raw['vip_product_type']===1);
  if(!$inside||($ids!==null&&!in_array($id,$ids,true))||!$label)continue;$rows[]=$raw;
 }
 usort($rows,static function($a,$b)use($ids,$price,$sales):int{
  if($ids!==null&&$price===''&&$sales===''){$r=array_search((int)$a['id'],$ids,true)<=>array_search((int)$b['id'],$ids,true);if($r)return $r;}
  if($price!==''){$r=bccomp((string)$a['price'],(string)$b['price'],2)*($price==='desc'?-1:1);if($r)return $r;}
  if($sales!==''){$r=((int)($a['sales']??0)+(int)($a['ficti']??0))<=>((int)($b['sales']??0)+(int)($b['ficti']??0));$r*=($sales==='desc'?-1:1);if($r)return $r;}
  $r=(int)$b['sort']<=>(int)$a['sort'];if($r)return $r;return $ids===null?((int)$b['id']<=>(int)$a['id']):0;
 });
 $limit=isset($query['limit'])?min((int)$query['limit'],100):0;if($limit)$rows=array_slice($rows,0,$limit);
 // getGoodsList calls isOpenMemberCard even for an empty list; this exact config read is independently included.
 m4uws_config($plan,'member_card_status',1);$out=[];
 foreach($rows as$r){$scores=[];foreach(m4uws_rows($plan,'eb_store_product_reply')as$v)if((int)$v['product_id']===(int)$r['id']&&(int)$v['is_del']===0)$scores[]=$v['product_score'];
  $r['star']=$scores?bcdiv((string)array_sum($scores),(string)count($scores),1):'5.0';$labelRows=[];
  if($r['label_list']!=='')foreach(explode(',',$r['label_list'])as$labelId){$found=[];foreach(m4uws_rows($plan,'eb_store_product_label')as$l)if((string)$l['id']===$labelId&&(int)$l['status']===1&&(int)$l['is_show']===1)$found[]=$l;
   m4uib_assert(count($found)<=1,'OBS_NATIVE_UNIQUE_LABEL');$labelRows[]=$found===[]?[]:array_intersect_key($found[0],array_fill_keys(['name','font_color','bg_color','border_color','image'],true));}
  $r['label_list']=$labelRows;$r['sales']=(int)($r['sales']??0)+(int)($r['ficti']??0);$row=m4uws_product($plan,$r,$uid,false);
  $fields=['id','mer_id','store_name','image','sales','price','stock','activity','ot_price','spec_type','unit_name','is_vip','vip_price','is_virtual','presale','virtual_type','min_qty','label_list','cart_button','star','checkCoupon'];
  $out[]=array_intersect_key($row,array_fill_keys($fields,true));
 }
 m4uib_assert(!m4uws_config($plan,'image_thumb_status',0),'OBS_ENABLED_THUMBNAILS_REQUIRE_THEIR_PRECISE_NORMAL_UPLOAD_SOURCE_MODEL');
 m4uib_assert(m4uwo_json($q['response_public'])===m4uwo_json($out),'OBS_COMPLETE_NATIVE_NORMAL_PRODUCTS_DTO_SOURCE_FILTER_SORT_STOCK_STAR_LABELS');
}

/** Exact normal H5 startup dispatcher. No unknown-path allowance and no null DTO substitute. */
function m4uws_is_path(string $path):bool
{
 return in_array($path,['/api/user','/api/basic_config','/api/copyright','/api/share','/api/wechat/get_logo','/api/get_script','/api/lang_version','/api/get_lang_json','/api/get_lang_type_list','/api/theme_info/home','/api/theme_info/theme','/api/theme/navigation','/api/cart/count?numType=0','/api/v2/get_today_coupon','/api/v2/new_coupon'],true)
 ||$path==='/api/products'||str_starts_with($path,'/api/products?');
}
function m4uws_read(array &$plan,array $q,array $before,array $state):bool
{
 $path=$q['path'];if(!m4uws_is_path($path))return false;
 $plan['current_review']=$q['sequence']<$plan['primary_sequence']?($state['review_before']??null):($state['review']??null);
 $plan['current_upload']=$q['sequence']<$plan['primary_sequence']?($state['upload_before']??null):($state['upload']??null);
 m4uib_assert($q['method']==='GET'&&$q['http_status']===200,'OBS_NORMAL_SOURCE_STARTUP_ONLY_REAL_GET200');
 if($path!=='/api/get_lang_json')m4uib_assert($q['request_public'] instanceof stdClass&&get_object_vars($q['request_public'])===[],'OBS_NORMAL_GET_BODY_IS_ACTUALLY_EMPTY_NOT_PROJECTED_NULL');
 $uid=$q['authentication']['present']?$state['prepare']['actor_ids']['customer']:0;$expected=null;$unordered=false;$special=false;
 if($path==='/api/user'){m4uws_personal_home($plan,$q,$uid);$special=true;}
 elseif(in_array($path,['/api/basic_config','/api/copyright','/api/share','/api/wechat/get_logo','/api/get_script'],true))$expected=m4uws_config_dto($plan,$path);
 elseif($path==='/api/lang_version')$expected=m4uws_language_version($plan,$before,$q);
 elseif($path==='/api/get_lang_json')$expected=m4uws_language_json($plan,$q);
 elseif($path==='/api/get_lang_type_list'){$expected=m4uws_language_list($plan['original']);$unordered=true;}
 elseif($path==='/api/theme_info/home'){$expected=m4uws_home_dto($plan,$uid);$special=true;
  m4uib_assert(is_array(m4uwo_plain($q['response_public']))&&m4uib_same(m4uws_normalize_home_labels(m4uwo_plain($q['response_public'])),m4uws_normalize_home_labels($expected)),'OBS_FULL_BEGIN_DERIVED_NORMAL_HOME_HYDRATION_NO_NULL_DTO');}
 elseif($path==='/api/theme_info/theme')$expected=m4uws_theme($plan['original'],'theme');
 elseif($path==='/api/theme/navigation')$expected=m4uws_theme($plan['original'],'navigation');
 elseif($path==='/api/cart/count?numType=0'){m4uws_cart_count($plan,$uid,m4uwo_plain($q['response_public']));$special=true;}
 elseif(in_array($path,['/api/v2/get_today_coupon','/api/v2/new_coupon'],true)){m4uws_coupon_read($plan,$before,$plan['event'],$q,$uid);$special=true;}
 elseif($path==='/api/products'||str_starts_with($path,'/api/products?')){m4uws_products_read($plan,$q,$uid);$special=true;}
 else m4uib_assert(false,'OBS_NO_UNKNOWN_NORMAL_STARTUP_SOURCE');
 if($path==='/api/get_script')m4uib_assert($q['app_status']===null&&$q['response_public']==='','OBS_REAL_RAW_EMPTY_SCRIPT_TEXT_NOT_JSON_SUCCESS_OR_NULL');
 else{m4uib_assert($q['app_status']===200,'OBS_REAL_NORMAL_STARTUP_APP_STATUS');
  if(!$special){if($unordered)m4uib_assert(is_array($q['response_public'])&&m4uws_unordered(m4uwo_plain($q['response_public']))===m4uws_unordered($expected),'OBS_COMPLETE_UNORDERED_NATIVE_LANGUAGE_TYPE_LIST');
   else m4uib_assert(m4uwo_json($q['response_public'])===m4uwo_json($expected),'OBS_COMPLETE_INDEPENDENT_NORMAL_STARTUP_DTO');}}
 m4uws_config_calls($plan,$q);return true;
}

function m4uws_required(string $name,array $event):void
{
 if(!in_array($name,['login-customer','n2-pending-public-read'],true))return;$counts=[];foreach($event['requests']as$q)$counts[$q['path']]=($counts[$q['path']]??0)+1;
 $required=$name==='login-customer'?['/api/basic_config','/api/theme_info/theme','/api/lang_version','/api/get_script','/api/wechat/get_logo','/api/user','/api/share','/api/theme_info/home','/api/cart/count?numType=0','/api/copyright']:['/api/logout','/api/share','/api/theme_info/home','/api/copyright'];
 foreach($required as$path)m4uib_assert(($counts[$path]??0)>=1,'OBS_COMPLETE_ACTUAL_NORMAL_APP_LOGIN_HOME_OR_LOGOUT_AUTOMATIC_READS');
 if($name==='login-customer')m4uib_assert(($counts['/api/v2/get_today_coupon']??0)+($counts['/api/v2/new_coupon']??0)>=1,'OBS_NORMAL_LOGGED_HOME_COUPON_SOURCE_BRANCH_ACTUALLY_OCCURS');
}


/** Source-exact batched SystemConfigService::more, independently derived from BEGIN/native cache. */
function m4uws_more(array &$plan,array $names,array $request):array
{
 $cache='system_config_'.md5(implode(',',$names));$value=m4uws_native_cache($plan['native_before'],$cache);
 if($value===null){$value=[];foreach($names as$name){$found=[];foreach($plan['original']['config']as$r)if($r['menu_name']===$name)$found[]=$r;
   m4uib_assert(count($found)<=1,'OBS_BATCH_CONFIG_UNIQUE_OR_ABSENT_BEGIN_ROW');$raw=$found===[]||$found[0]['value']===null?null:json_decode($found[0]['value'],true);$value[$name]=$raw??'';}}
 m4uib_assert(is_array($value)&&!array_is_list($value),'OBS_COMPLETE_NATIVE_BATCH_CONFIG_MAP');
 m4uws_cache_call($plan,$cache,$value,0,$request);return $value;
}
function m4uws_upload_configs(array &$plan,array $request):void
{
 m4uib_assert((int)m4uws_config($plan,'upload_type',1)===1,'OBS_NORMAL_PUBLIC_UPLOAD_ACTUAL_LOCAL_DRIVER_ONLY');
 $thumb=m4uws_more($plan,['image_thumb_status','thumb_big_height','thumb_big_width','thumb_mid_height','thumb_mid_width','thumb_small_height','thumb_small_width'],$request);
 m4uws_more($plan,['image_watermark_status','watermark_type','watermark_image','watermark_opacity','watermark_position','watermark_rotate','watermark_text','watermark_text_angle','watermark_text_color','watermark_text_size','watermark_x','watermark_y'],$request);
 m4uib_assert(array_key_exists('image_thumb_status',$thumb)&&!$thumb['image_thumb_status'],'OBS_NORMAL_UNTRANSFORMED_PNG_LOCAL_BRANCH_NO_THUMB_OR_WATER');
 // PublicController also asks upload_type for attachmentAdd image_type after move. Same raw source cache value.
 m4uws_config($plan,'upload_type',1);m4uws_config_calls($plan,$request);
}
/** PersonalHome is safely projected to uid, but every mandatory sys_config read remains in the full native plan. */
function m4uws_personal_home(array &$plan,array $q,int $uid):void
{
 m4uib_assert($uid>0&&$q['authentication']['present']===true&&$q['authentication']['kind']==='Bearer'&&$q['authentication']['subject_id']===$uid,'OBS_PERSONAL_HOME_REAL_CUSTOMER_AUTH');
 $u=m4ar_one($plan['native_before']['rows']['eb_user'],'uid',$uid);$interval=m4uwo_interval($q);
 m4uib_assert((int)$u['agent_level']===0,'OBS_NATIVE_NONZERO_AGENT_LEVEL_REQUIRES_ITS_PRECISE_DTO_CHAIN');
 m4uib_assert((int)$u['is_ever_level']!==0||(int)$u['is_money_level']<=0||(int)$u['overdue_time']>=$interval[1],'OBS_NATIVE_EXPIRED_PAID_MEMBER_WRITE_IS_OUTSIDE_THIS_NORMAL_WINDOW_MODEL');
 m4uws_config($plan,'member_card_status',1);$status=m4uws_config($plan,'store_brokerage_statu');
 if(!$u['is_promoter']){
  if(m4uws_config($plan,'brokerage_func_status')){
   $branch=m4uws_config($plan,'store_brokerage_statu',1);$promote=$branch==2;
   if($branch!=1&&$branch!=2){$threshold=m4uws_config($plan,'store_brokerage_price',0);$paid='0';
    foreach($plan['native_before']['rows']['eb_store_order']as$r)if((int)$r['uid']===$uid&&(int)$r['paid']===1&&(int)$r['refund_status']===0)$paid=bcadd($paid,(string)$r['pay_price'],2);$promote=(float)$paid>=(float)$threshold;}
   m4uib_assert(!$promote,'OBS_NATIVE_PERSONAL_HOME_PROMOTER_WRITE_REQUIRES_SEPARATE_BUSINESS_ACCEPTANCE');
  }
 }
 m4uib_assert(!m4uws_config($plan,'member_func_status'),'OBS_ENABLED_LEVEL_DTO_REQUIRES_ITS_PRECISE_NORMAL_SOURCE_CHAIN');
 m4uws_config($plan,'recharge_switch');m4uws_config($plan,'extract_time');m4uws_config($plan,'balance_func_status',0);
 m4uws_config($plan,'invoice_func_status',0);m4uws_config($plan,'special_invoice_status',0);
 if(m4uws_config($plan,'brokerage_func_status')&&$u['spread_open']){
  $status=m4uws_config($plan,'store_brokerage_statu');$threshold=m4uws_config($plan,'store_brokerage_price');$paid='0';
  foreach($plan['native_before']['rows']['eb_store_order']as$r)if((int)$r['uid']===$uid&&(int)$r['paid']===1)$paid=bcadd($paid,(string)$r['pay_price'],2);
  $promote=$u['is_promoter']||$status==2||($status==3&&(float)$paid>(float)$threshold);
  m4uib_assert(!$promote||$u['is_promoter'],'OBS_NATIVE_CHECK_PROMOTER_DOES_NOT_ADD_AN_UNMODELED_USER_WRITE');
 }
 m4uws_config($plan,'member_card_status');m4uws_config($plan,'extract_type');m4uws_config($plan,'division_status',0);m4uws_config($plan,'agent_apply_open',0);m4uws_config($plan,'h5_avatar');
 if(strpos((string)$u['avatar'],'/statics/system_images/')!==false)m4uws_set_file($plan,$u['avatar']);m4uws_config($plan,'member_func_status');
 // UserBrokerageDao105-107 frozen sum and90-96 yesterday sum are read-only; DiyServices321-324 is direct native value.
 m4uwo_expect_read($q,['uid'=>$uid]);m4uws_config_calls($plan,$q);
}


/** Task2 ordinary-state target preparation only. No definitions below execute on import. */
function m5pt_signed(mixed $value):int
{
 m4up_expect(is_int($value)||(is_string($value)&&preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D',$value)===1),'TASK2_NATIVE_SIGNED_INTEGER');
 $text=(string)$value;m4up_expect(bccomp($text,'-2147483648',0)>=0&&bccomp($text,'4294967295',0)<=0,'TASK2_NATIVE_SIGNED_DOMAIN');return (int)$text;
}
function m5pt_match(array $rows,string $column,int $id):array
{
 // Association filtering is signed/nullable; unrelated historical rows are not asserted positive.
 $found=[];foreach($rows as$row)if($row[$column]!==null&&m5pt_signed($row[$column])===$id)$found[]=$row;
 m4up_expect(count($found)===1,'TASK2_EXACT_ONE_MATCHED_NATIVE_ROW');return $found[0];
}
function m5pt_next(array $snapshot,string $table):string
{return m5pcp_u64($snapshot['database']['tables'][$table]['metadata']['table']['AUTO_INCREMENT']);}
function m5pt_same_window(array $a,array $b):void
{
 m4up_expect($a['database']===$b['database']&&$a['rows']===$b['rows']&&$a['redis']['keys']===$b['redis']['keys']
  &&$a['tag_members']===$b['tag_members']&&$a['files']===$b['files'],'TASK2_SECOND_FULL_NATIVE_WINDOW_IDENTICAL');
}
function m5pt_component_join(array $left,array $right):void
{
 foreach(['database_sha256','redis_sha256','files_sha256','table_count']as$key)
  m4up_expect($left[$key]===$right[$key],'TASK2_REAL_WINDOW_COMPONENT_CONTINUITY');
}
function m5pt_observer_source(array $source):?array
{
 static $originalTree=null;$tree=[];
 if(!array_key_exists('task2_observer_source',$source['freeze']))return null;
 $tools=$source['freeze']['task2_observer_source'];m4c_entry_keys($tools,['schema','scope','core_source_sha','host_source_root','container_root','members']);
 m4up_expect($tools['schema']===1&&$tools['scope']==='MS5_TASK2_COMPOSITE_OBSERVER_SOURCE_ONLY'
  &&$tools['core_source_sha']===$source['source_sha']&&is_string($tools['host_source_root'])&&$tools['host_source_root']!==''
  &&!str_contains($tools['host_source_root'],"\0")&&$tools['container_root']==='/run/audit/task2-observer'
  &&is_array($tools['members'])&&array_is_list($tools['members'])&&count($tools['members'])===3,'TASK2_EXACT_INITIAL_OBSERVER_SOURCE6');
 $root=$tools['container_root'];m4up_expect(is_dir($root)&&!is_link($root)&&realpath($root)===$root,'TASK2_REAL_TOOL_ROOT');
 $names=array_values(array_diff(scandir($root),['.','..']));sort($names,SORT_STRING);
 m4up_expect($names===['entry.php','expected.php','gate.php'],'TASK2_EXACT_THREE_TOOL_NAMESPACE');
 foreach($names as$i=>$name){$member=$tools['members'][$i];m4c_entry_keys($member,['path','bytes','sha256']);$path=$root.'/'.$name;
  m4up_expect($member['path']===$name&&is_int($member['bytes'])&&$member['bytes']>0&&m5pc_hex($member['sha256'])
   &&is_file($path)&&!is_link($path)&&realpath($path)===$path,'TASK2_EXACT_ORDINARY_TOOL_MEMBER');
  $stat=lstat($path);m4up_expect(is_array($stat),'TASK2_TOOL_NATIVE_STAT');$fields=array_flip(['dev','ino','mode','nlink','uid','gid','size','mtime','ctime']);$stat=array_intersect_key($stat,$fields);m4up_expect(($stat['mode']&0170000)===0100000&&$stat['size']===$member['bytes']
   &&hash_file('sha256',$path)===$member['sha256'],'TASK2_FROZEN_TOOL_BYTES');
  clearstatcache(true,$path);m4up_expect(array_intersect_key(lstat($path),$fields)===$stat,'TASK2_FROZEN_TOOL_STAT_STABLE');$tree[$name]=$stat;
 }
 if($originalTree===null)$originalTree=$tree;else m4up_expect($tree===$originalTree,'TASK2_ORIGINAL_TOOL_NATIVE_IDENTITIES_UNCHANGED');
 m5pt_copy_receipt($source,$tools);return $tools;
}
function m5pt_copy_receipt(array $source,array $tools):string
{
 $path='/run/audit/evidence/task2-observer-copy.json';$descriptor=$source['context']['task2_observer_copy']??null;
 m4up_expect(is_array($descriptor),'TASK2_REAL_COPY_RECEIPT_CONTEXT_DESCRIPTOR');m4c_entry_keys($descriptor,['path','bytes','sha256']);
 m4up_expect($descriptor['path']==='evidence/task2-observer-copy.json'&&is_int($descriptor['bytes'])&&$descriptor['bytes']>0
  &&m5pc_hex($descriptor['sha256'])&&is_file($path)&&!is_link($path)&&realpath($path)===$path
  &&filesize($path)===$descriptor['bytes']&&hash_file('sha256',$path)===$descriptor['sha256'],'TASK2_ORIGINAL_HOST_COPY_RECEIPT_BYTES');
 $receipt=m4c_entry_read($path);m4c_entry_keys($receipt,['schema','scope','run_id','source_sha','freeze_sha256','observer_source_sha256','originals','copies']);
 m4up_expect($receipt['schema']===1&&$receipt['scope']==='MS5_TASK2_OBSERVER_SOURCE_COPY'&&$receipt['run_id']===$source['run_id']
  &&$receipt['source_sha']===$source['source_sha']&&$receipt['freeze_sha256']===$source['freeze_sha256']
  &&$receipt['observer_source_sha256']===hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical($tools))
  &&is_array($receipt['originals'])&&array_is_list($receipt['originals'])&&count($receipt['originals'])===3
  &&is_array($receipt['copies'])&&array_is_list($receipt['copies'])&&count($receipt['copies'])===3,'TASK2_EXACT_HOST_COPY_IDENTITY8');
 foreach(['entry.php','expected.php','gate.php']as$i=>$name){$original=$receipt['originals'][$i];$copy=$receipt['copies'][$i];
  m4c_entry_keys($original,['path','bytes','sha256']);m4c_entry_keys($copy,['path','bytes','sha256']);$member=$tools['members'][$i];
  $host=rtrim(str_replace('\\','/',$tools['host_source_root']),'/').'/'.$name;
  m4up_expect(is_string($original['path'])&&str_replace('\\','/',$original['path'])===$host
   &&$copy['path']==='task2-observer/'.$name&&$original['bytes']===$member['bytes']&&$copy['bytes']===$member['bytes']
   &&$original['sha256']===$member['sha256']&&$copy['sha256']===$member['sha256'],'TASK2_EXACT_THREE_ORIGINAL_AND_RUN_COPY_DESCRIPTORS');
 }
 m4up_expect(hash_file('sha256',$path)===$descriptor['sha256']&&filesize($path)===$descriptor['bytes'],'TASK2_COPY_RECEIPT_COMPLETE_READ_STABLE');
 return $descriptor['sha256'];
}
function m5pt_actors(array $actors):array
{
 return ['customer'=>['id'=>m5pcp_int($actors['customer']['id'],1)],
  'merchant'=>['id'=>m5pcp_int($actors['merchant']['id'],1),'role_id'=>m5pcp_int($actors['merchant']['role_id'],1),'mer_id'=>m5pcp_int($actors['merchant']['mer_id'],1,4294967295)],
  'platform'=>['id'=>m5pcp_int($actors['platform']['id'],1),'role_id'=>m5pcp_int($actors['platform']['role_id'],1)]];
}
function m5pt_target(array $root):array
{return ['id'=>m5pcp_int($root['id'],1),'order_no'=>$root['order_id'],'uid'=>m5pcp_int($root['uid'],1),'mer_id'=>m5pcp_int($root['mer_id'],1,4294967295)];}
function m5pt_log(array $before,array $actual,int $uid,int $product,string $type,int $stamp):array
{
 $values=['id'=>m5pt_next($before,'eb_store_product_log'),'type'=>$type,'uid'=>$uid,'product_id'=>$product,
  'visit_num'=>0,'cart_num'=>$type==='cart'?1:0,'order_num'=>$type==='order'?1:0,'pay_num'=>0,'pay_price'=>'0.00',
  'cost_price'=>'0.00','pay_uid'=>0,'refund_num'=>0,'refund_price'=>'0.00','collect_num'=>0,'add_time'=>$stamp];
 return m4upo_row($before,$actual,'eb_store_product_log',$values);
}
function m5pt_files_unchanged(array $before,array $after):void
{m4up_expect($after['files']===$before['files'],'TASK2_EVERY_PRIVATE_PUBLIC_RUNTIME_FILE_STAT_AND_BYTES_UNCHANGED');}
function m5pt_cart(PDO $db,\think\App $app,array $before,int $uid,array $product,array $sku):array
{
 $productId=m5pcp_int($product['id'],1,8388607);$started=time();
 foreach($before['rows']['eb_store_cart']as$old){
  if($old['uid']===null||m5pt_signed($old['uid'])!==$uid||$old['product_id']===null||m5pt_signed($old['product_id'])!==$productId
   ||$old['product_attr_unique']!==$sku['unique'])continue;
  $eligible=true;foreach(['type','is_pay','is_del','is_new','combination_id','seckill_id','bargain_id','advance_id']as$key)
   if($old[$key]===null||m5pt_signed($old[$key])!==0)$eligible=false;
  if($eligible&&($old['status']===null||m5pt_signed($old['status'])===1))m4up_expect(false,'TASK2_SELECTED_SKU_HAS_NO_MERGEABLE_CART');
 }
 $ref=\app\services\marketplace\order\MarketplaceOrderInput::reference($app->make(\app\services\order\StoreCartServices::class)
  ->setCart($uid,$productId,1,$sku['unique'],0,false),'persistent');
 $after=m4upo_snapshot($db);m4up_expect($ref===m5pt_next($before,'eb_store_cart'),'TASK2_REAL_NATURAL_DISTINCT_CART_REFERENCE');
 $actual=m4upo_one($after['rows']['eb_store_cart'],'id',$ref);$stamp=m4upo_time($actual['add_time'],[$started,time()]);
 $cart=m4upo_row($before,$actual,'eb_store_cart',['id'=>$ref,'uid'=>$uid,'product_id'=>$productId,'cart_num'=>1,
  'product_attr_unique'=>$sku['unique'],'type'=>0,'add_time'=>$stamp,'seckill_id'=>0,'bargain_id'=>0,'combination_id'=>0,'advance_id'=>0]);
 $logs=m4ar_new_rows($before['rows']['eb_store_product_log'],$after['rows']['eb_store_product_log']);m4up_expect(count($logs)===1,'TASK2_ONE_REAL_CART_LOG');
 $log=m5pt_log($before,$logs[0],$uid,$productId,'cart',m4upo_time($logs[0]['add_time'],[$started,time()]));
 m4ar_database_delta($before,$after,['eb_store_cart'=>[...$before['rows']['eb_store_cart'],$cart],
  'eb_store_product_log'=>[...$before['rows']['eb_store_product_log'],$log]],['eb_store_cart'=>1,'eb_store_product_log'=>1]);
 m4ar_redis_delta($before,$after);m4up_expect($after['tag_members']===$before['tag_members'],'TASK2_CART_ALL_TAG_MEMBERS_UNCHANGED');m5pt_files_unchanged($before,$after);
 $again=m4upo_snapshot($db);m5pt_same_window($after,$again);return ['reference'=>$ref,'row'=>$cart,'snapshot'=>$after];
}
function m5pt_forms(array $product,string $ref):array
{
 $raw=json_decode($product['custom_form'],true,32,JSON_THROW_ON_ERROR);m4up_expect(is_array($raw)&&array_is_list($raw)&&count($raw)===2,'TASK2_CURRENT_C3_TWO_FORM_FIELDS');
 $fields=$values=[];foreach($raw as$i=>$field){m4c_entry_keys($field,['title','label','value','status']);
  m4up_expect(is_string($field['title'])&&is_bool($field['status'])&&is_string($field['value'])
   &&in_array($field['label'],['text','number'],true),'TASK2_INDEPENDENT_ORDINARY_FORM_DOMAIN');
  $fields[]=['field_index'=>$i]+$field;$values[]=['field_index'=>$i,'value'=>$field['label']==='number'?'0':'普通页面填写'.$product['store_name']];
 }
 $productId=m5pcp_int($product['id'],1,8388607);$hash=hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical(['product_id'=>$productId,'fields'=>$fields]));
 $definition=['product_id'=>$productId,'product_name'=>$product['store_name'],'cart_refs'=>[$ref],'definition_sha256'=>$hash,'fields'=>$fields];
 $saved=$definition;foreach($saved['fields']as$i=>&$field)$field['value']=$values[$i]['value'];unset($field);
 return ['answers'=>[['product_id'=>$productId,'definition_sha256'=>$hash,'values'=>$values]],'saved'=>[$saved]];
}
function m5pt_saved_cart(array $before,array $cart,array $product,array $sku,array $merchant,string $reserve):array
{
 // PublicCatalogQuery raw native root+merchant join, displayRoot getter, then ordinary private calculation.
 $productId=m5pcp_int($product['id'],1,8388607);$product['_public_store_mer_id']=$merchant['mer_id'];$product['_public_store_name']=$merchant['name'];
 $product['_public_store_logo']=$merchant['logo'];$product['_public_store_after_sale_contact']=$merchant['after_sale_contact'];$product['_public_store_shipping_note']=$merchant['shipping_note'];
 $product['slider_image']=is_string($product['slider_image'])?json_decode($product['slider_image'],true,32,JSON_THROW_ON_ERROR):[];
 $descriptions=[];foreach($before['rows']['eb_store_product_description']as$row)
  if($row['product_id']!==null&&m5pt_signed($row['product_id'])===$productId&&$row['type']!==null&&m5pt_signed($row['type'])===0)$descriptions[]=$row;
 m4up_expect(count($descriptions)<=1,'TASK2_ONE_NATIVE_PRODUCT_DESCRIPTION_OR_ABSENT');$product['description']=isset($descriptions[0])?htmlspecialchars_decode($descriptions[0]['description']):'';
 $product['attrInfo']=$sku;$product['express_delivery']=in_array('1',explode(',',$product['logistics']),true);
 $product['store_mention']=in_array('2',explode(',',$product['logistics']),true);$product['attrInfo']['image']=$sku['image']??$product['image']??'';
 $product['attrInfo']['suk']=$sku['suk']??'已失效';
 $cart['id']=m5pcp_u64($cart['id']);$cart['uid']=m5pcp_int($cart['uid'],1);$cart['product_id']=$productId;
 $cart['productInfo']=$product;$cart['min_qty']=$product['min_qty'];$cart['attrStatus']=isset($sku['stock'])&&(bool)$sku['stock'];
 $cart['vip_truePrice']='0.00';$cart['costPrice']=$sku['cost'];$cart['trueStock']=$sku['stock'];
 $cart['truePrice']=$sku['price'];$cart['sum_price']=$sku['price'];$cart['price_type']='level';$cart['is_valid']=1;
 $cart['postage_price']=$cart['origin_postage_price']=$product['postage'];$cart['coupon_id']=0;$cart['sum_true_price']=$sku['price'];
 foreach(['coupon_price','integral_price','one_brokerage','two_brokerage','staff_brokerage','agent_brokerage','division_brokerage']as$key)$cart[$key]='0.00';
 $cart['use_integral']=0;$cart['marketplace']=['cart_ref'=>$cart['id'],'sku_id'=>m5pcp_int($sku['id'],1),'sku_type'=>0,'sku_unique'=>$sku['unique'],'reserve_id'=>$reserve];return $cart;
}
function m5pt_g0(array $snapshot,array $root):array
{
 $id=m5pcp_int($root['id'],1);$actual=m5pt_match($snapshot['rows']['eb_marketplace_payment_registry'],'order_id',$id);
 $values=['order_id'=>$id,'uid'=>m5pcp_int($root['uid'],1),'mer_id'=>m5pcp_int($root['mer_id'],1,4294967295),
  'payment_contract_version'=>1,'born_at'=>m5pcp_int($root['add_time'],1,4294967295),'attempt_generation'=>0,
  'winner_attempt_id'=>null,'fulfillment_hold'=>0,'hold_reason'=>null,'hold_attempt_id'=>null,'hold_at'=>null];
 $registry=m4upo_row($snapshot,$actual,'eb_marketplace_payment_registry',$values);
 foreach($snapshot['rows']['eb_marketplace_payment_attempt']as$row)
  m4up_expect($row['order_id']===null||m5pt_signed($row['order_id'])!==$id,'TASK2_REAL_G0_NO_PAYMENT_ATTEMPT');
 foreach(['paid','pay_time','status','refund_status']as$key)m4up_expect(m5pt_signed($root[$key])===0,'TASK2_REAL_G0_UNPAID_UNFULFILLED');
 m4up_expect($root['pay_type']===''&&$root['trade_no']==='','TASK2_REAL_G0_NO_CHANNEL_OR_TRADE');return $registry;
}
function m5pt_sequence(PDO $db,string $number):array
{
 m4up_expect(PHP_INT_SIZE===8&&date_default_timezone_get()==='Asia/Shanghai'
  &&str_starts_with($number,'cp')&&preg_match('/\A[1-9][0-9]{0,18}\z/D',substr($number,2))===1,'TASK2_REAL_ORDER_NUMBER_SNOWFLAKE');
 $suffix=substr($number,2);m4up_expect(bccomp($suffix,'9223372036854775807',0)<=0,'TASK2_SNOWFLAKE_NATIVE_RANGE');
 $offset=bcdiv($suffix,'4194304',0);$key=bcadd('1640966400000',$offset,0);
 m4up_expect(bccomp($offset,'2199023255551',0)<=0&&preg_match('/\A[1-9][0-9]{12}\z/D',$key)===1,'TASK2_EXACT_SEQUENCE_KEY_FROM_REAL_ORDER');
 return m5pcp_sequence_expiry_barrier($db,['epoch_ms'=>'1640966400000','source_id_count'=>1,
  'keys'=>[hash('sha256',$key)=>['key'=>$key,'sources'=>[['source_kind'=>'persistent_order','identifier_sha256'=>hash('sha256',$number)]]]]]);
}
function m5pt_created_redis(array $before,array $after,int $uid,string $key):void
{
 $raw='c:user_order_'.$uid.$key;$tag='c:CRMEB:'.md5('crmeb');$members=$before['tag_members'];
 m4up_expect(!isset($before['redis']['keys'][hash('sha256',$raw)])&&!in_array($raw,$members[$tag],true),'TASK2_NEW_GENUINE_CONFIRMATION_IDENTITY');
 $members[$tag][]=$raw;sort($members[$tag],SORT_STRING);m4up_expect($members===$after['tag_members'],'TASK2_ONLY_OWN_CONFIRMATION_TAG_APPEND');
 m4up_expect(!isset($after['redis']['keys'][hash('sha256',$raw)]),'TASK2_REAL_POST_COMMIT_CONFIRMATION_CONSUMED');
 $expected=$before['redis']['keys'];$tagHash=hash('sha256',$tag);m4up_expect(isset($expected[$tagHash])&&$expected[$tagHash]['type']==='set'
  &&$expected[$tagHash]['expires']===-1,'TASK2_ORIGINAL_PERMANENT_CRMEB_TAG');
 $expected[$tagHash]=['type'=>'set','sha256'=>hash('sha256',"set\0".serialize($members[$tag])),'expires'=>-1];ksort($expected,SORT_STRING);
 m4up_expect($after['redis']['keys']===$expected,'TASK2_ALL_OTHER_REDIS_VALUES_TYPES_AND_ABSOLUTE_EXPIRIES_UNCHANGED');
}
function m5pt_create(PDO $db,\think\App $app,array $before,array $fixture,int $uid,int $mer):array
{
 $selected=$fixture['selected'][0];$product=m5pt_match($before['rows']['eb_store_product'],'id',m5pcp_int($selected['product_id'],1,8388607));
 $sku=m5pt_match($before['rows']['eb_store_product_attr_value'],'id',m5pcp_int($selected['sku_id'],1));
 $buyer=m5pt_match($before['rows']['eb_user'],'uid',$uid);
 $merchant=m5pt_match($before['rows']['eb_marketplace_merchant'],'mer_id',$mer);$address=m5pt_match($before['rows']['eb_user_address'],'id',m5pcp_int($fixture['address']['id'],1));
 $invoice=m5pt_match($before['rows']['eb_user_invoice'],'id',m5pcp_int($fixture['invoice']['id'],1));
 m4up_expect(m5pcp_int($product['mer_id'],1,4294967295)===$mer&&m5pcp_int($sku['product_id'],1)===(int)$product['id']
  &&m5pcp_int($sku['type'],0,0)===0&&m5pcp_int($sku['is_show'],1,1)===1&&m5pcp_int($sku['stock'],1)>=1
  &&m5pcp_int($product['stock'],1)>=1&&m5pcp_int($product['freight'],2,2)===2&&m5pcp_int($product['temp_id'],0,0)===0
  &&m5pcp_int($product['is_virtual'],0,0)===0&&$product['logistics']==='1'&&m5pcp_int($product['min_qty'],1,1)===1
  &&m5pcp_int($address['uid'],1)===$uid&&m5pcp_int($invoice['uid'],1)===$uid,'TASK2_CURRENT_C3_ORDINARY_OWNER_SKU_AND_ADDRESS');
 foreach(['member_func_status','member_card_status','member_price_status','image_thumb_status']as$name){
  $config=[];foreach($before['rows']['eb_system_config']as$row)if($row['menu_name']===$name)$config[]=$row;
  m4up_expect(count($config)===1&&json_decode($config[0]['value'],true,32,JSON_THROW_ON_ERROR)===0,'TASK2_CURRENT_C3_DISABLED_MEMBERSHIP_AND_THUMB');
 }
 $cancelConfig=[];foreach($before['rows']['eb_system_config']as$row)if($row['menu_name']==='order_cancel_time')$cancelConfig[]=$row;
 m4up_expect(count($cancelConfig)===1&&json_decode($cancelConfig[0]['value'],true,32,JSON_THROW_ON_ERROR)==='0','TASK2_C3_ORIGINAL_NO_AUTOMATIC_DEADLINE');
 $freeConfig=[];foreach($before['rows']['eb_system_config']as$row)if($row['menu_name']==='store_free_postage')$freeConfig[]=$row;
 m4up_expect(count($freeConfig)===1&&json_decode($freeConfig[0]['value'],true,32,JSON_THROW_ON_ERROR)==='10000.00'
  &&bccomp($sku['price'],'10000.00',2)<0,'TASK2_ORIGINAL_C3_FREE_POSTAGE_THRESHOLD_NOT_REACHED');
 $freeConfig=[];foreach($before['rows']['eb_system_config']as$row)if($row['menu_name']==='store_free_postage')$freeConfig[]=$row;
 m4up_expect(count($freeConfig)===1&&json_decode($freeConfig[0]['value'],true,32,JSON_THROW_ON_ERROR)==='10000.00'
  &&bccomp($sku['price'],'10000.00',2)<0,'TASK2_ORIGINAL_C3_FREE_POSTAGE_THRESHOLD_NOT_REACHED');
 foreach(['price','cost']as$money)m4up_expect(is_string($sku[$money])&&preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}\z/D',$sku[$money])===1,'TASK2_NATIVE_CANONICAL_SKU_MONEY');
 m4up_expect(bccomp($sku['price'],'0.01',2)>=0&&is_string($product['postage'])&&preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}\z/D',$product['postage'])===1,'TASK2_ORDINARY_POSITIVE_PRICE_AND_FIXED_POSTAGE');
 $cartStage=m5pt_cart($db,$app,$before,$uid,$product,$sku);$cartBefore=$cartStage['snapshot'];$ref=$cartStage['reference'];$forms=m5pt_forms($product,$ref);$started=time();
 $addressId=m5pcp_int($address['id'],1,16777215);$choice=\app\services\marketplace\order\MarketplaceOrderInput::confirm(['mer_id'=>$mer,'cartId'=>$ref,'addressId'=>$addressId,'new'=>false]);
 $user=$app->make(\app\services\user\UserServices::class)->getUserInfo($uid);m4up_expect($user!==null&&m5pcp_int($user->getData('uid'),1)===$uid,'TASK2_SAME_REAL_AUTHENTICATED_BUYER');
 $confirmation=$app->make(\app\services\order\StoreOrderServices::class)->getOrderConfirmData($user->toArray(),$ref,false,$addressId,1,0,$choice);
 $key=\app\services\marketplace\order\MarketplaceOrderInput::confirmationKey($confirmation['orderKey']);unset($confirmation);
 $raw=['cartId'=>$ref,'mer_id'=>$mer,'new'=>0,'idem_key'=>'confirm:'.$key,'addressId'=>$addressId,'invoice_id'=>m5pcp_int($invoice['id'],1),
  'payType'=>'','mark'=>'普通页面下单','gift_mark'=>'普通页面文本','real_name'=>'','phone'=>'','shipping_type'=>1,'is_gift'=>0,'store_id'=>0,
  'couponId'=>0,'useIntegral'=>false,'combinationId'=>0,'pinkId'=>0,'seckill_id'=>0,'bargainId'=>0,'advanceId'=>0,'custom_form'=>$forms['answers']];
 $input=\app\services\marketplace\order\MarketplaceOrderInput::create($uid,$key,$raw);
 $created=$app->make(\app\services\marketplace\order\MarketplaceOrderCreateServices::class)->create($uid,$key,$raw);
 m4up_expect(is_string($created['order_id']??null)&&($created['key']??null)===$key,'TASK2_REAL_SERVICE_RETURNED_ORDER_IDENTITY');
 $number=$created['order_id'];$ended=time();$sequence=m5pt_sequence($db,$number);$after=m4upo_snapshot($db);
 $id=m5pcp_int(m5pt_next($cartBefore,'eb_store_order'),1);$actual=m5pt_match($after['rows']['eb_store_order'],'id',$id);$stamp=m4upo_time($actual['add_time'],[$started,$ended]);
 $price=$sku['price'];$postage=$product['postage'];$orderValues=['id'=>$id,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$number,'unique'=>$key,
  'cart_id'=>['json'=>[$ref]],'total_num'=>1,'total_price'=>$price,'total_postage'=>$postage,'pay_price'=>bcadd($price,$postage,2),'pay_postage'=>$postage,
  'cost'=>$sku['cost'],'add_time'=>$stamp,'pay_uid'=>$uid,'marketplace_trade_version'=>1,'shipping_type'=>1,'is_gift'=>0,'paid'=>0,'is_cancel'=>0,
  'real_name'=>$address['real_name'],'user_phone'=>$address['phone'],'user_address'=>$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail'],
  'mark'=>'普通页面下单','gift_mark'=>'普通页面文本','pay_type'=>'','channel_type'=>$buyer['user_type'],'province'=>'','custom_form'=>['json'=>$forms['saved']],
  'merchant_name_snapshot'=>$merchant['name'],'merchant_after_sale_contact_snapshot'=>$merchant['after_sale_contact']];
 $root=m4upo_row($cartBefore,$actual,'eb_store_order',$orderValues);m4up_expect($root['order_id']===$number,'TASK2_REAL_COMMITTED_RETURNED_ORDER_JOIN');
 $registry=m5pt_g0($after,$root);$reserve=m5pt_next($cartBefore,'eb_marketplace_stock_movement');
 $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>(int)$product['id'],'sku_id'=>(int)$sku['id'],
  'sku_type'=>0,'sku_unique'=>$sku['unique'],'qty'=>1,'kind'=>'reserve','cart_refs'=>[$ref]];
 $moveValues=['id'=>$reserve,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>(int)$product['id'],'sku_id'=>(int)$sku['id'],'sku_type'=>0,'sku_unique'=>$sku['unique'],
  'qty'=>1,'kind'=>'reserve','idem_key'=>'reserve:'.$id.':'.$sku['id'],'parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,'actor_type'=>'customer','actor_id'=>$uid,
  'request_digest'=>hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical($action),true),'sku_stock_before'=>$sku['stock'],'sku_stock_after'=>(int)$sku['stock']-1,
  'sku_sales_before'=>$sku['sales'],'sku_sales_after'=>(int)$sku['sales']+1,'root_stock_before'=>$product['stock'],'root_stock_after'=>(int)$product['stock']-1,
  'root_sales_before'=>$product['sales'],'root_sales_after'=>(int)$product['sales']+1,'add_time'=>$stamp];
 $move=m4upo_row($cartBefore,m4upo_one($after['rows']['eb_marketplace_stock_movement'],'id',$reserve),'eb_marketplace_stock_movement',$moveValues);
 $savedCart=m5pt_saved_cart($cartBefore,$cartStage['row'],$product,$sku,$merchant,$reserve);$itemId=m5pcp_int(m5pt_next($cartBefore,'eb_store_order_cart_info'),1);
 $item=m4upo_row($cartBefore,m5pt_match($after['rows']['eb_store_order_cart_info'],'id',$itemId),'eb_store_order_cart_info',
  ['id'=>$itemId,'oid'=>$id,'uid'=>$uid,'mer_id'=>$mer,'cart_id'=>$ref,'product_id'=>(int)$product['id'],'cart_num'=>1,'surplus_num'=>1,'refund_num'=>0,'split_status'=>0,
   'unique'=>md5($ref.$id),'cart_info'=>['json'=>$savedCart]]);
 $invoiceValues=['id'=>m5pt_next($cartBefore,'eb_store_order_invoice'),'uid'=>$uid,'category'=>'order','order_id'=>$id,'invoice_id'=>$invoice['id'],
  'is_pay'=>0,'is_refund'=>0,'is_invoice'=>0,'add_time'=>$stamp];
 foreach(['header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number']as$field)$invoiceValues[$field]=$invoice[$field];
 $invoiceRow=m4upo_row($cartBefore,m4upo_one($after['rows']['eb_store_order_invoice'],'id',$invoiceValues['id']),'eb_store_order_invoice',$invoiceValues);
 $ledgerRows=[];foreach($after['rows']['eb_marketplace_order_idempotency']as$row)
  if($row['uid']!==null&&m5pt_signed($row['uid'])===$uid&&$row['idem_key']===$input['idem_key'])$ledgerRows[]=$row;
 m4up_expect(count($ledgerRows)===1,'TASK2_EXACT_ONE_REAL_CREATED_LEDGER');
 $ledger=m4upo_row($cartBefore,$ledgerRows[0],'eb_marketplace_order_idempotency',['uid'=>$uid,'mer_id'=>$mer,'idem_key'=>$input['idem_key'],
  'request_digest'=>hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical($input['request']),true),'confirmation_ref'=>hash('sha256',$key,true),
  'source_kind'=>'persistent','cart_refs'=>['json'=>[$ref]],'state'=>1,'order_id'=>$id,'post_commit_done'=>1,'cancel_at'=>0,'add_time'=>$stamp]);
 $statuses=m4ar_new_rows($cartBefore['rows']['eb_store_order_status'],$after['rows']['eb_store_order_status']);m4up_expect(count($statuses)===1,'TASK2_ONE_REAL_CREATE_STATUS');
 $status=m4upo_row($cartBefore,$statuses[0],'eb_store_order_status',['oid'=>$id,'change_type'=>'cache_key_create_order','change_message'=>'订单生成','change_time'=>$stamp]);
 $logs=m4ar_new_rows($cartBefore['rows']['eb_store_product_log'],$after['rows']['eb_store_product_log']);m4up_expect(count($logs)===1,'TASK2_ONE_REAL_CREATED_PRODUCT_LOG');
 $log=m5pt_log($cartBefore,$logs[0],$uid,(int)$product['id'],'order',$stamp);
 $productAfter=$product;$productAfter['stock']=(int)$product['stock']-1;$productAfter['sales']=(int)$product['sales']+1;
 $skuAfter=$sku;$skuAfter['stock']=(int)$sku['stock']-1;$skuAfter['sales']=(int)$sku['sales']+1;
 $expected=['eb_store_order'=>[...$cartBefore['rows']['eb_store_order'],$root],
  'eb_marketplace_payment_registry'=>[...$cartBefore['rows']['eb_marketplace_payment_registry'],$registry],
  'eb_marketplace_stock_movement'=>[...$cartBefore['rows']['eb_marketplace_stock_movement'],$move],
  'eb_store_order_cart_info'=>[...$cartBefore['rows']['eb_store_order_cart_info'],$item],
  'eb_store_order_invoice'=>[...$cartBefore['rows']['eb_store_order_invoice'],$invoiceRow],
  'eb_marketplace_order_idempotency'=>[...$cartBefore['rows']['eb_marketplace_order_idempotency'],$ledger],
  'eb_store_order_status'=>[...$cartBefore['rows']['eb_store_order_status'],$status],
  'eb_store_product_log'=>[...$cartBefore['rows']['eb_store_product_log'],$log],
  'eb_store_product'=>m4ar_replaced($cartBefore['rows']['eb_store_product'],'id',$product['id'],$productAfter),
  'eb_store_product_attr_value'=>m4ar_replaced($cartBefore['rows']['eb_store_product_attr_value'],'id',$sku['id'],$skuAfter),
  'eb_store_cart'=>$before['rows']['eb_store_cart']];
 $counts=[];foreach(['eb_store_order','eb_marketplace_stock_movement','eb_store_order_cart_info','eb_store_order_invoice','eb_store_product_log']as$table)$counts[$table]=1;
 m4ar_database_delta($cartBefore,$after,$expected,$counts);m5pt_created_redis($cartBefore,$after,$uid,$key);m5pt_files_unchanged($cartBefore,$after);
 $again=m4upo_snapshot($db);m5pt_same_window($after,$again);
 return ['root'=>$root,'item'=>$item,'reserve'=>$move,'snapshot'=>$after,
  'proof'=>['target'=>m5pt_target($root),'source_fingerprint'=>hash('sha256',serialize([$root,$item,$ledger,$registry,$move,$invoiceRow])),
   'cart_reference_sha256'=>hash('sha256',$ref),'request_sha256'=>hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical($input['request'])),
   'before'=>m4upo_descriptor($before),'cart'=>m4upo_descriptor($cartBefore),'after'=>m4upo_descriptor($after),'verified_after'=>m4upo_descriptor($again),
   'payment_generation'=>0,'payment_attempt_count'=>0,'sequence'=>$sequence]];
}
function m5pt_cancel(PDO $db,\think\App $app,array $created):array
{
 $before=$created['snapshot'];$root=$created['root'];$item=$created['item'];$parent=$created['reserve'];$uid=m5pcp_int($root['uid'],1);$id=m5pcp_int($root['id'],1);$mer=m5pcp_int($root['mer_id'],1,4294967295);
 m5pt_g0($before,$root);m4up_expect(m5pt_signed($root['is_cancel'])===0,'TASK2_NEW_TARGET_UNCANCELLED_BEFORE_ONE_NORMAL_CALL');$started=time();
 $result=$app->make(\app\services\marketplace\order\MarketplaceOrderCancelServices::class)->cancelForCustomer($uid,$root['order_id']);$ended=time();
 m4up_expect($result===['state'=>'cancelled','order_id'=>$root['order_id']],'TASK2_REAL_G0_CANCELLED_RESPONSE');$after=m4upo_snapshot($db);
 $new=$root;$new['is_cancel']=1;$releaseId=m5pt_next($before,'eb_marketplace_stock_movement');$actual=m4upo_one($after['rows']['eb_marketplace_stock_movement'],'id',$releaseId);
 $stamp=m4upo_time($actual['add_time'],[$started,$ended]);$product=m5pt_match($before['rows']['eb_store_product'],'id',(int)$parent['product_id']);
 $sku=m5pt_match($before['rows']['eb_store_product_attr_value'],'id',(int)$parent['sku_id']);$parentId=m5pcp_u64($parent['id']);
 $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>(int)$parent['product_id'],'sku_id'=>(int)$parent['sku_id'],
  'sku_type'=>0,'sku_unique'=>$parent['sku_unique'],'qty'=>1,'kind'=>'release','parent_reserve_id'=>$parentId];
 $values=['id'=>$releaseId,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$parent['product_id'],'sku_id'=>$parent['sku_id'],'sku_type'=>0,'sku_unique'=>$parent['sku_unique'],
  'qty'=>1,'kind'=>'release','idem_key'=>'release:'.$id.':'.$parentId,'parent_reserve_id'=>$parentId,'order_item_id'=>null,'refund_id'=>null,'actor_type'=>'customer','actor_id'=>$uid,
  'request_digest'=>hash('sha256',\app\services\marketplace\order\MarketplaceOrderInput::canonical($action),true),
  'sku_stock_before'=>$sku['stock'],'sku_stock_after'=>(int)$sku['stock']+1,'sku_sales_before'=>$sku['sales'],'sku_sales_after'=>(int)$sku['sales']-1,
  'root_stock_before'=>$product['stock'],'root_stock_after'=>(int)$product['stock']+1,'root_sales_before'=>$product['sales'],'root_sales_after'=>(int)$product['sales']-1,'add_time'=>$stamp];
 $release=m4upo_row($before,$actual,'eb_marketplace_stock_movement',$values);$product['stock']=(int)$product['stock']+1;$product['sales']=(int)$product['sales']-1;
 $sku['stock']=(int)$sku['stock']+1;$sku['sales']=(int)$sku['sales']-1;$statuses=m4ar_new_rows($before['rows']['eb_store_order_status'],$after['rows']['eb_store_order_status']);
 m4up_expect(count($statuses)===1,'TASK2_ONE_REAL_CUSTOMER_CANCEL_STATUS');$status=m4upo_row($before,$statuses[0],'eb_store_order_status',
  ['oid'=>$id,'change_type'=>'cancel_order','change_message'=>'订单取消','change_time'=>$stamp]);
 m4ar_database_delta($before,$after,['eb_store_order'=>m4ar_replaced($before['rows']['eb_store_order'],'id',$id,$new),
  'eb_marketplace_stock_movement'=>[...$before['rows']['eb_marketplace_stock_movement'],$release],
  'eb_store_order_status'=>[...$before['rows']['eb_store_order_status'],$status],
  'eb_store_product'=>m4ar_replaced($before['rows']['eb_store_product'],'id',$product['id'],$product),
  'eb_store_product_attr_value'=>m4ar_replaced($before['rows']['eb_store_product_attr_value'],'id',$sku['id'],$sku)],['eb_marketplace_stock_movement'=>1]);
 m4ar_redis_delta($before,$after);m4up_expect($after['tag_members']===$before['tag_members'],'TASK2_CANCEL_ALL_REDIS_TAGS_UNCHANGED');m5pt_files_unchanged($before,$after);
 $registry=m5pt_g0($after,$new);m4up_expect(m5pt_match($after['rows']['eb_store_order'],'id',$id)===$new
  &&m5pt_match($after['rows']['eb_store_order_cart_info'],'id',(int)$item['id'])===$item,'TASK2_CANCEL_ONLY_HEADER_FLAG_AND_EXACT_STOCK_RELEASE');
 $again=m4upo_snapshot($db);m5pt_same_window($after,$again);
 return ['root'=>$new,'snapshot'=>$after,'proof'=>['before'=>m4upo_descriptor($before),'after'=>m4upo_descriptor($after),'verified_after'=>m4upo_descriptor($again),
  'native_release_sha256'=>hash('sha256',serialize($release)),'native_cancel_status_sha256'=>hash('sha256',serialize($status)),
  'unchanged_saved_item_sha256'=>hash('sha256',serialize($item)),'payment_generation'=>0,'payment_attempt_count'=>0,'cancel_call_count'=>1]];
}
function m5pt_prepare(PDO $db,\think\App $app,array $source,array $owner,array $actors,array $original):array
{
 $tools=m5pt_observer_source($source);m4up_expect($tools!==null,'TASK2_EXACT_FROZEN_SOURCE_PRESENT');$before=m4upo_snapshot($db);
 m5pt_component_join($original['after'],m4upo_descriptor($before));$ids=m5pt_actors($actors);$uid=m5pcp_int($owner['uid'],1);$mer=m5pcp_int($owner['root']['mer_id'],1,4294967295);
 m4up_expect($ids['customer']['id']===$uid&&$ids['merchant']['mer_id']===$mer&&($GLOBALS['m4ar_child_proof']['stopped']??false)===true
  &&!is_resource($GLOBALS['m4ar_http_process']??null),'TASK2_ORIGINAL_ACTORS_SINGLE_HANDOFF_AND_CLOSED_CHILD');
 m4up_phase('task2_normal_unpaid_target');$unpaid=m5pt_create($db,$app,$before,$owner['fixture'],$uid,$mer);
 m4up_phase('task2_normal_cancellation_target');$toCancel=m5pt_create($db,$app,$unpaid['snapshot'],$owner['fixture'],$uid,$mer);
 m4up_expect($unpaid['root']['id']!==$toCancel['root']['id']&&$unpaid['root']['order_id']!==$toCancel['root']['order_id']
  &&$unpaid['item']['cart_id']!==$toCancel['item']['cart_id'],'TASK2_TWO_REAL_DISTINCT_TARGET_IDENTITIES');
 $cancelled=m5pt_cancel($db,$app,$toCancel);$after=$cancelled['snapshot'];
 m4up_expect(m5pt_match($after['rows']['eb_store_order'],'id',(int)$owner['root']['id'])===$owner['root']
  &&m5pt_match($after['rows']['eb_store_order'],'id',(int)$unpaid['root']['id'])===$unpaid['root'],'TASK2_ORIGINAL_PAID_AND_NEW_UNPAID_HEADERS_UNCHANGED');
 m5pt_g0($after,$unpaid['root']);m5pt_observer_source($source);m4fn_inputs_unchanged($owner['inputs']);
 $again=m4upo_snapshot($db);m5pt_same_window($after,$again);$descriptor=m4upo_descriptor($after);
 $binding=['run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],'freeze_sha256'=>$source['freeze_sha256'],
  'prepare_container_id'=>$source['container_id'],'native_created_sha256'=>$source['native_created_sha256'],
  'source_binding_sha256'=>$source['source_binding_sha256'],'context_sha256'=>hash_file('sha256','/run/audit/actual-run-context.json')];
 $paid=['target'=>m5pt_target($owner['root']),'source_fingerprint'=>m4fn_source_oracle($owner['saved'])['fingerprint']];
 $public=['schema'=>1,'scope'=>'MS5_TASK2_NORMAL_TARGET_PREPARATION_ONLY','status'=>'VERIFIED_MS5_TASK2_NORMAL_TARGET_PREPARATION_ONLY',
  'binding'=>$binding,'observer_source'=>$tools,'actors'=>$ids,'paid'=>$paid,
  'unpaid'=>['target'=>m5pt_target($unpaid['root']),'creation'=>$unpaid['proof']],
  'cancelled'=>['target'=>m5pt_target($cancelled['root']),'creation'=>$toCancel['proof'],'cancellation'=>$cancelled['proof']],
  'window'=>['original_before'=>$original['before'],'original_after'=>$original['after'],'increment_before'=>m4upo_descriptor($before),
   'after'=>$descriptor,'verified_after'=>m4upo_descriptor($again)],
  'preservation'=>['database_tables'=>count($after['database']['names']),'old_paid_source_fingerprint'=>$paid['source_fingerprint'],
   'original_files_sha256'=>m4upo_descriptor($before)['files_sha256'],'final_files_sha256'=>$descriptor['files_sha256'],
   'all_non_targets_verified'=>true,'source_unchanged'=>true,'single_private_handoff'=>true,'no_new_child_or_listener'=>true,'observer_copy_sha256'=>m5pt_copy_receipt($source,$tools)],'diagnostic'=>null];
 return ['after'=>$descriptor,'public'=>$public];
}
