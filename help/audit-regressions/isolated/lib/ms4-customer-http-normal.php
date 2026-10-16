<?php
declare(strict_types=1);

/** Source-only business helper for the finite enabled customer HTTP entry.
 * No source gate, child lifecycle, public writer, synthetic identity or order writer lives here.
 */
use app\services\marketplace\order\MarketplaceCartSelection;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\order\MarketplaceTradePolicy;
use app\services\user\LoginServices;
use think\db\ConnectionInterface;
use think\facade\Db;

const M4CB_FIXTURE = '/run/audit-fixtures/ms4-customer-fixture.json';
const M4CB_TABLES = ['eb_store_order','eb_store_order_invoice','eb_store_order_cart_info',
    'eb_marketplace_order_idempotency','eb_marketplace_stock_movement','eb_store_product','eb_store_product_attr_value',
    'eb_store_cart','eb_user','eb_marketplace_merchant','eb_store_product_attr','eb_user_address','eb_user_invoice',
    'eb_system_config','eb_system_user_level','eb_user_level','eb_member_right','eb_store_order_status',
    'eb_store_product_log','eb_system_timer','eb_marketplace_role','eb_marketplace_employee',
    'eb_marketplace_password_setup','eb_system_event','eb_system_notification'];
const M4CB_INSERT_TABLES = ['eb_user','eb_marketplace_merchant','eb_store_product','eb_store_product_attr',
    'eb_store_product_attr_value','eb_user_address','eb_user_invoice','eb_system_config'];

function m4c_expect(bool $ok,string $code): void
{
    if(preg_match('/\AM4C_[A-Z0-9_]+\z/D',$code)!==1)throw new RuntimeException('M4C_FIXED_ASSERTION_CODE');
    $phase=$GLOBALS['m4c_phase']??'business_source';
    $GLOBALS['m4c_assertions']=($GLOBALS['m4c_assertions']??0)+1;
    $GLOBALS['m4c_assertions_by_phase'][$phase]=($GLOBALS['m4c_assertions_by_phase'][$phase]??0)+1;
    if(!$ok){$GLOBALS['m4c_failed_assertion']=$code;throw new RuntimeException($code);}
}

function m4cb_phase(string $phase): void { $GLOBALS['m4c_phase']=$phase; }
/** Same native read-only isolation facts as guard.php; throwing keeps entry child cleanup reachable. */
function m4cb_guard_readonly(): void
{
    try{
        $manifest=audit_manifest();$runId=getenv('AUDIT_RUN_ID');
        m4c_expect(($manifest['guard_mode']??null)==='read-only','M4C_GUARD_REQUIRES_REAL_READ_ONLY_MODE');
        m4c_expect(getenv('AUDIT_ISOLATED')==='1'&&preg_match('/^\d{14}[a-f0-9]{6}$/D',(string)$runId)===1
            &&($manifest['run_id']??'')===$runId&&($manifest['project']??'')==='tianli-audit-'.$runId
            &&($manifest['network_internal']??false)===true,'M4C_GUARD_ACTUAL_RUN_IDENTITY_AND_NETWORK');
        foreach(['audit-mysql','audit-redis']as$host){
            $address=gethostbyname($host);
            m4c_expect(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false
                &&$address===($manifest['services'][$host]['address']??'')&&!empty($manifest['services'][$host]['id']),
                'M4C_GUARD_ACTUAL_DATABASE_CACHE_ENDPOINT');
        }
        $config=audit_config();m4c_expect(($config['run_id']??'')===$runId&&($config['database']['username']??'')==='audit_app',
            'M4C_GUARD_ACTUAL_PRIVATE_APPLICATION_IDENTITY');
        $pdo=audit_db();m4c_expect($pdo->query('SELECT DATABASE()')->fetchColumn()==='audit_auth'
            &&$pdo->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN)===[$runId],
            'M4C_GUARD_ACTUAL_DATABASE_SENTINEL');
        m4c_expect(audit_redis()->get('audit:sentinel')===$runId,'M4C_GUARD_ACTUAL_REDIS_SENTINEL');
        m4c_expect(audit_grants_are_restricted($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN)),
            'M4C_GUARD_EXACT_RESTRICTED_DML_GRANTS');
    }catch(Throwable $error){
        // Preserve fixed assertion codes only, never native driver messages or a private previous exception.
        $code=$GLOBALS['m4c_failed_assertion']??'M4C_READ_ONLY_ISOLATION_GUARD';
        if(!is_string($code)||preg_match('/\AM4C_[A-Z0-9_]+\z/D',$code)!==1)$code='M4C_READ_ONLY_ISOLATION_GUARD';
        $GLOBALS['m4c_failed_assertion']=$code;throw new RuntimeException($code);
    }
}
function m4cb_json(array $value): string
{
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
function m4cb_rows(PDO $pdo,string $sql,array $args=[]): array
{
    $statement=$pdo->prepare($sql);m4c_expect($statement instanceof PDOStatement,'M4C_NATIVE_PREPARE');
    foreach(array_values($args)as$i=>$value)m4c_expect($statement->bindValue($i+1,$value===null?null:(string)$value,
        $value===null?PDO::PARAM_NULL:PDO::PARAM_STR),'M4C_NATIVE_BIND');
    m4c_expect($statement->execute(),'M4C_NATIVE_EXECUTE');return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function m4cb_text_row(array $row): array
{
    $out=[];foreach($row as$key=>$value){m4c_expect($value===null||is_scalar($value),'M4C_NATIVE_SCALAR_COLUMN');$out[$key]=$value===null?null:(string)$value;}
    return $out;
}
function m4cb_equal_row(array $actual,array $expected): void
{
    m4c_expect(array_keys($actual)===array_keys($expected)&&m4cb_text_row($actual)===m4cb_text_row($expected),'M4C_FULL_NATIVE_ROW_EQUAL');
}
function m4cb_transaction_pdo(ConnectionInterface $connection): PDO
{
    $pdo=$connection->getPdo();$default=Db::connect();
    m4c_expect($pdo instanceof PDO&&$connection===$default&&$pdo===$default->getPdo()&&$pdo->inTransaction()
        &&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        &&$pdo->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
        &&in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),'M4C_REAL_DEFAULT_TRANSACTION_PDO');
    return $pdo;
}
function m4cb_insert(PDO $pdo,string $table,array $values,string $pk='id'): array
{
    m4c_expect(in_array($table,M4CB_INSERT_TABLES,true)&&in_array($pk,['id','uid','mer_id'],true),'M4C_ONLY_NATIVE_FIXTURE_TABLE');
    foreach(array_keys($values)as$field)m4c_expect(preg_match('/\A[a-z_][a-z0-9_]*\z/D',$field)===1,'M4C_FIXED_FIXTURE_COLUMN');
    $columns=implode(',',array_map(static fn(string $field):string=>'`'.$field.'`',array_keys($values)));
    $statement=$pdo->prepare('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.implode(',',array_fill(0,count($values),'?')).')');
    m4c_expect($statement instanceof PDOStatement,'M4C_FIXTURE_NATIVE_PREPARE');
    foreach(array_values($values)as$i=>$value)m4c_expect($statement->bindValue($i+1,$value===null?null:(string)$value,
        $value===null?PDO::PARAM_NULL:PDO::PARAM_STR),'M4C_FIXTURE_NATIVE_BIND');
    m4c_expect($statement->execute()&&$statement->rowCount()===1,'M4C_FIXTURE_NATIVE_INSERT');$id=$pdo->lastInsertId();
    m4c_expect(is_string($id)&&preg_match('/\A[1-9][0-9]*\z/D',$id)===1,'M4C_DATABASE_GENERATED_FIXTURE_PK');
    $rows=m4cb_rows($pdo,'SELECT * FROM `'.$table.'` WHERE `'.$pk.'` = ?',[$id]);
    m4c_expect(count($rows)===1&&(string)$rows[0][$pk]===$id,'M4C_FIXTURE_PK_READBACK');
    foreach($values as$field=>$value)m4c_expect($value===null?$rows[0][$field]===null:(string)$rows[0][$field]===(string)$value,'M4C_FIXTURE_VALUE_READBACK');
    return $rows[0];
}
function m4cb_snapshot(PDO $pdo): array
{
    m4c_expect(!$pdo->inTransaction(),'M4C_INDEPENDENT_READ_OUTSIDE_TRANSACTION');$out=[];
    foreach(M4CB_TABLES as$table){$order=match($table){
        'eb_marketplace_order_idempotency'=>'`uid`,`idem_key`','eb_store_order_status'=>'`oid`,`change_type`,`change_message`,`change_time`',
        'eb_user'=>'`uid`','eb_marketplace_merchant'=>'`mer_id`',default=>'`id`'};
        $out[$table]=m4cb_rows($pdo,'SELECT * FROM `'.$table.'` ORDER BY '.$order);
    }return $out;
}
function m4cb_owner_auth_only(array $before,array $after,int $uid,bool $login=false): void
{
    m4c_expect(count($before)===count($after),'M4C_ALL_USERS_RETAINED');$seen=0;
    foreach($before as$i=>$old){$new=$after[$i];m4c_expect((string)$old['uid']===(string)$new['uid'],'M4C_USER_ORDER_AND_IDENTITY');
        if((int)$old['uid']===$uid){++$seen;$expected=$old;
            m4c_expect((int)$new['last_time']>=(int)$old['last_time']&&(int)$new['last_time']<=time(),'M4C_OWNER_REAL_AUTH_TIMESTAMP');
            $expected['last_time']=$new['last_time'];
            if($login){m4c_expect(is_string($new['last_ip'])&&filter_var($new['last_ip'],FILTER_VALIDATE_IP)!==false,'M4C_OWNER_REAL_LOGIN_IP');$expected['last_ip']=$new['last_ip'];}
            m4cb_equal_row($new,$expected);
        }else m4cb_equal_row($new,$old);
    }m4c_expect($seen===1,'M4C_EXACT_REAL_OWNER_AUTH_ROW');
}
function m4cb_preserved(array $before,array $after,int $uid,array $changed=[],bool $login=false): void
{
    m4c_expect(array_keys($before)===M4CB_TABLES&&array_keys($after)===M4CB_TABLES,'M4C_EXACT_TWENTY_FIVE_FACT_TABLES');
    foreach(M4CB_TABLES as$table){
        if($table==='eb_user'){m4cb_owner_auth_only($before[$table],$after[$table],$uid,$login);continue;}
        if(in_array($table,$changed,true))continue;
        m4c_expect($before[$table]===$after[$table],'M4C_OTHER_FULL_BUSINESS_TABLE_UNCHANGED');
    }
}
function m4cb_events(PDO $pdo): array
{
    $events=m4cb_rows($pdo,'SELECT * FROM `eb_system_event` WHERE `mark` IN (?,?) AND `is_del` = 0 AND `is_open` = 1',['user_login','user_add_cart']);
    m4c_expect($events===[],'M4C_NO_ENABLED_LOGIN_OR_CART_CUSTOM_EVENTS');
    $notices=m4cb_rows($pdo,'SELECT * FROM `eb_system_notification` WHERE `custom_trigger` = ?',['login_success']);
    foreach($notices as$row)foreach(['is_system','is_sms','is_wechat','is_routine','is_ent_wechat']as$key)
        m4c_expect(array_key_exists($key,$row)&&(int)$row[$key]===0,'M4C_LOGIN_NOTICE_CHANNELS_ACTUALLY_DISABLED');
    $queue=m4cb_rows($pdo,'SELECT `value` FROM `eb_system_config` WHERE `menu_name` = ?',['queue_open']);
    m4c_expect(count($queue)===1&&json_decode($queue[0]['value'],true,32,JSON_THROW_ON_ERROR)===0,'M4C_REAL_SYNCHRONOUS_CART_STATS_CONFIGURATION');
    return ['custom_event_count'=>0,'login_notice_rows'=>count($notices),'external_channels_enabled'=>0,'queue_open'=>0];
}
function m4cb_private_meta(): array
{
    clearstatcache(true,M4CB_FIXTURE);$parent=dirname(M4CB_FIXTURE);$stat=lstat(M4CB_FIXTURE);
    m4c_expect(function_exists('posix_geteuid')&&is_dir($parent)&&!is_link($parent)&&(fileperms($parent)&0777)===0700
        &&is_array($stat)&&($stat['mode']&0170000)===0100000&&!is_link(M4CB_FIXTURE)
        &&($stat['mode']&0777)===0600&&$stat['uid']===posix_geteuid()&&fileowner($parent)===posix_geteuid()
        &&$stat['size']>0&&$stat['size']<=2097152,'M4C_PRIVATE_FIXTURE_NATIVE_MODE_OWNER_SIZE');
    return $stat;
}
function m4cb_private_write(array $fixture): array
{
    $parent=dirname(M4CB_FIXTURE);m4c_expect(is_dir($parent)&&!is_link($parent)&&(fileperms($parent)&0777)===0700
        &&!file_exists(M4CB_FIXTURE)&&!is_link(M4CB_FIXTURE),'M4C_PRIVATE_FIXTURE_CREATE_ONLY');
    $raw=m4cb_json($fixture);m4c_expect(strlen($raw)<=2097152,'M4C_PRIVATE_FIXTURE_BOUNDED_BYTES');
    $mask=umask(0077);$stream=fopen(M4CB_FIXTURE,'xb');
    try{m4c_expect(is_resource($stream)&&chmod(M4CB_FIXTURE,0600)&&fwrite($stream,$raw)===strlen($raw)&&fflush($stream),'M4C_PRIVATE_FIXTURE_COMPLETE_NATIVE_WRITE');}
    finally{if(is_resource($stream))fclose($stream);umask($mask);}
    $stat=m4cb_private_meta();$read=file_get_contents(M4CB_FIXTURE);
    m4c_expect(is_string($read)&&$read===$raw&&$stat['size']===strlen($raw),'M4C_PRIVATE_FIXTURE_EXACT_READBACK');
    return ['fixture_sha256_prefix'=>substr(hash('sha256',$raw),0,12),'fixture_bytes'=>strlen($raw)];
}

function m4c_app(string $mode,array $source): \think\App
{
    m4cb_phase('business_app');m4c_expect(in_array($mode,['prepare','verify'],true)&&empty($GLOBALS['m4c_app_started']),'M4C_ONE_REAL_APP_PER_NATIVE_PROCESS');
    $GLOBALS['m4c_app_started']=true;require_once '/workspace/crmeb/vendor/autoload.php';
    $config=audit_config();m4c_expect(($config['run_id']??null)===$source['run_id']&&($config['database']['username']??null)==='audit_app'
        &&getenv('APP_ENV')==='testing'&&getenv('PHP_MARKETPLACE_ENABLED')==='true'&&getenv('PHP_MARKETPLACE_TRADE_ENABLED')==='true'
        &&getenv('PHP_CACHE_DRIVER')==='redis','M4C_REAL_ENABLED_PRIVATE_APP_CONFIGURATION');
    foreach($source['environment']as$name=>$value)m4c_expect(getenv($name)===$value,'M4C_SOURCE_BOUND_REAL_ENVIRONMENT_VALUE');
    m4c_expect(($config['auth']['session_epoch']??null)==='audit-http-'.$source['run_id']
        &&is_string($config['signing_key']??null)&&strlen($config['signing_key'])>=32
        &&is_string($config['auth']['stamp_key']??null)&&strlen($config['auth']['stamp_key'])>=32
        &&!hash_equals($config['signing_key'],$config['auth']['stamp_key'])&&is_string($config['auth']['issuer']??null),'M4C_REAL_PRIVATE_AUTH_BINDING');
    $app=new \think\App('/workspace/crmeb/');require_once '/workspace/crmeb/vendor/topthink/framework/src/helper.php';
    m4c_expect(function_exists('app')&&app()===$app&&!$app->initialized(),'M4C_REAL_APP_WITHOUT_SYNTHETIC_BOOTSTRAP');
    $runtime='/tmp/ms4-customer-http-'.$source['run_id'].'-'.$mode.'/';
    m4c_expect(!file_exists($runtime)&&mkdir($runtime,0700),'M4C_DISTINCT_OWN_NATIVE_RUNTIME');$app->setRuntimePath($runtime);
    $app->env->set(['APP_DEBUG'=>false,'APP_APP_KEY'=>$config['signing_key'],'DATABASE_HOSTNAME'=>'audit-mysql','DATABASE_HOSTPORT'=>'3306',
        'DATABASE_DATABASE'=>'audit_auth','DATABASE_USERNAME'=>'audit_app','DATABASE_PASSWORD'=>$config['database']['password'],
        'DATABASE_PREFIX'=>'eb_','DATABASE_CHARSET'=>'utf8mb4','DATABASE_DEBUG'=>false,'REDIS_REDIS_HOSTNAME'=>'audit-redis',
        'REDIS_PORT'=>'6379','REDIS_REDIS_PASSWORD'=>$config['redis_password'],'REDIS_SELECT'=>0,'CACHE_CACHE_PREFIX'=>'c:',
        'CACHE_CACHE_TAG_PREFIX'=>'CRMEB:','AUTH_SESSION_EPOCH'=>$config['auth']['session_epoch'],
        'AUTH_STAMP_KEY'=>$config['auth']['stamp_key'],'AUTH_ISSUER'=>$config['auth']['issuer']]);
    $app->register(\app\AppService::class);$app->bootService($app->getService(\app\AppService::class));
    $app->bind(require '/workspace/crmeb/app/provider.php');
    foreach(['app','cache','log','marketplace','auth']as$name)$app->config->set(require '/workspace/crmeb/config/'.$name.'.php',$name);
    $database=require '/workspace/crmeb/config/database.php';
    $database['connections']['mysql']['params']=[PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
    $database['connections']['mysql']['break_reconnect']=false;$app->config->set($database,'database');
    require_once '/workspace/crmeb/app/common.php';$app->register(\think\service\ModelService::class);
    $app->bootService($app->getService(\think\service\ModelService::class));date_default_timezone_set($app->config->get('app.default_timezone','Asia/Shanghai'));
    $self=MarketplaceOrderInput::unsigned($app->config->get('marketplace.self_operated_mer_id'),'self_operated_mer_id');
    m4c_expect($app->db instanceof \crmeb\compat\think\Db&&$app->config->get('marketplace.enabled')===true
        &&$app->config->get('marketplace.trade.enabled')===true&&MarketplaceTradePolicy::v1CreateEnabled()===true
        &&$self>0&&$self===$source['self_operated_mer_id'],'M4C_LOADED_REAL_ENABLED_BOOLS_AND_SELF_ID');
    m4c_expect($app->config->get('cache.default')==='redis'&&$app->config->get('cache.stores.redis.type')==='redis'
        &&$app->config->get('cache.stores.redis.host')==='audit-redis'&&(string)$app->config->get('cache.stores.redis.port')==='6379'
        &&$app->config->get('cache.stores.redis.select')===0&&$app->config->get('cache.stores.redis.prefix')==='c:'
        &&$app->config->get('cache.stores.redis.password')===$config['redis_password']
        &&$app->env->get('app.app_key')===$config['signing_key']&&$app->config->get('auth.session_epoch')===$config['auth']['session_epoch']
        &&$app->config->get('auth.stamp_key')===$config['auth']['stamp_key']&&$app->config->get('auth.issuer')===$config['auth']['issuer'],
        'M4C_REAL_SAME_REDIS_AUTH_AND_CACHE_PREFIX');
    $row=m4cb_rows(audit_db(),'SELECT `mer_id` FROM `eb_marketplace_merchant` WHERE `mer_id` = ?',[$self]);
    m4c_expect(count($row)===1&&(int)$row[0]['mer_id']===$self,'M4C_ACTUAL_MS2_SELF_ROW_PRESENT');
    $GLOBALS['m4c_application']=$app;return $app;
}

function m4c_prepare_fixture(array $source): array
{
    m4cb_phase('business_prepare_fixture');audit_guard_assert();$app=$GLOBALS['m4c_application']??null;
    m4c_expect($app instanceof \think\App&&app()===$app,'M4C_PREPARE_USES_ROOT_BOOTSTRAPPED_REAL_APP');
    $fixture=Db::transaction(static function(ConnectionInterface $connection):array{
        $pdo=m4cb_transaction_pdo($connection);$now=time();
        foreach(['eb_user','eb_store_product','eb_store_cart','eb_user_address','eb_user_invoice','eb_system_config',
            'eb_store_order','eb_store_order_invoice','eb_store_order_cart_info','eb_marketplace_order_idempotency','eb_marketplace_stock_movement','eb_store_product_log']as$table)
            m4c_expect((int)m4cb_rows($pdo,'SELECT COUNT(*) AS `n` FROM `'.$table.'`')[0]['n']===0,'M4C_FRESH_NO_BUSINESS_FIXTURE_PREDECESSOR');
        foreach(['station_open'=>1,'store_free_postage'=>'10000.00','image_thumb_status'=>0,'member_func_status'=>0,
            'member_card_status'=>0,'member_price_status'=>0,'store_self_mention'=>0,'invoice_func_status'=>1,'special_invoice_status'=>0,
            'offline_pay_status'=>1,'offline_postage'=>0,'integral_ratio'=>'0.00','queue_open'=>0,'order_cancel_time'=>'0']as$key=>$value)
            m4cb_insert($pdo,'eb_system_config',['menu_name'=>$key,'type'=>'text','value'=>json_encode($value,JSON_THROW_ON_ERROR)]);
        $user=m4cb_insert($pdo,'eb_user',['account'=>'ms4c-'.getenv('AUDIT_RUN_ID'),'nickname'=>'普通HTTP顾客','user_type'=>'h5',
            'is_money_level'=>0,'status'=>1,'is_del'=>0,'spread_uid'=>0,'add_time'=>$now],'uid');
        $merchant=m4cb_insert($pdo,'eb_marketplace_merchant',['name'=>'通用HTTP店铺','review_status'=>1,'accept_orders'=>1,'fulfillment_enabled'=>1,
            'security_locked'=>0,'after_sale_contact'=>'专用隔离售后','add_time'=>$now,'update_time'=>$now],'mer_id');
        $products=$skus=$selected=[];$unselected=null;
        foreach([['name'=>'甲','price'=>'20.15','cost'=>'7.45'],['name'=>'乙','price'=>'30.25','cost'=>'9.35']]as$index=>$spec){
            $forms=[['title'=>'同名资料','label'=>'text','status'=>true,'value'=>'']];
            if($index===0)$forms[]=['title'=>'同名资料','label'=>'number','status'=>false,'value'=>''];
            $product=m4cb_insert($pdo,'eb_store_product',['mer_id'=>$merchant['mer_id'],'store_name'=>'通用HTTP商品'.$spec['name'],'unit_name'=>'件',
                'spec_type'=>1,'stock'=>20,'sales'=>0,'price'=>$spec['price'],'cost'=>$spec['cost'],'postage'=>'2.67','freight'=>2,'temp_id'=>0,
                'is_show'=>1,'review_status'=>2,'is_vip'=>0,'logistics'=>'1','min_qty'=>1,'is_limit'=>0,
                'image'=>'/__ms4_fixture__/product.png','slider_image'=>'["/__ms4_fixture__/product.png"]',
                'custom_form'=>json_encode($forms,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'add_time'=>$now]);
            $products[(int)$product['id']]=$product;
            m4cb_insert($pdo,'eb_store_product_attr',['product_id'=>$product['id'],'type'=>0,'attr_name'=>'规格','attr_values'=>$index===0?'选中,未选':'选中']);
            $sku=m4cb_insert($pdo,'eb_store_product_attr_value',['product_id'=>$product['id'],'type'=>0,'unique'=>bin2hex(random_bytes(4)),
                'suk'=>'选中','stock'=>$index===0?8:20,'sales'=>0,'price'=>$spec['price'],'cost'=>$spec['cost'],'is_show'=>1,
                'is_virtual'=>0,'weight'=>'0.00','volume'=>'0.00','image'=>'/__ms4_fixture__/product.png']);
            $skus[(int)$sku['id']]=$sku;$selected[]=['product_id'=>(int)$product['id'],'sku_id'=>(int)$sku['id'],'qty'=>$index===0?2:1];
            if($index===0){$other=m4cb_insert($pdo,'eb_store_product_attr_value',['product_id'=>$product['id'],'type'=>0,
                'unique'=>bin2hex(random_bytes(4)),'suk'=>'未选','stock'=>12,'sales'=>0,'price'=>'24.35','cost'=>'8.25','is_show'=>1,
                'is_virtual'=>0,'weight'=>'0.00','volume'=>'0.00','image'=>'/__ms4_fixture__/product.png']);
                $skus[(int)$other['id']]=$other;$unselected=['product_id'=>(int)$product['id'],'sku_id'=>(int)$other['id'],'qty'=>1];}
        }
        $address=m4cb_insert($pdo,'eb_user_address',['uid'=>$user['uid'],'real_name'=>'隔离普通顾客','phone'=>'00000000000',
            'province'=>'验证省','city'=>'验证市','district'=>'验证区','detail'=>'专用HTTP地址','is_default'=>1,'add_time'=>$now]);
        $invoice=m4cb_insert($pdo,'eb_user_invoice',['uid'=>$user['uid'],'header_type'=>1,'type'=>1,'name'=>'普通HTTP抬头','is_default'=>1,'add_time'=>$now]);
        m4c_expect(m4cb_transaction_pdo($connection)===$pdo,'M4C_FIXTURE_ONE_REAL_DEFAULT_TRANSACTION');
        return compact('user','merchant','products','skus','selected','unselected','address','invoice');
    });
    $password=bin2hex(random_bytes(12));m4c_expect($app->make(LoginServices::class)->reset($fixture['user']['account'],$password)===true,'M4C_PRODUCTION_PASSWORD_RESET');
    $rows=m4cb_rows(audit_db(),'SELECT * FROM `eb_user` WHERE `uid` = ?',[$fixture['user']['uid']]);
    m4c_expect(count($rows)===1&&$rows[0]['pwd']===md5($password),'M4C_PRODUCTION_PASSWORD_NATIVE_READBACK');$fixture['user']=$rows[0];
    foreach(['spread_uid','agent_id','division_id','staff_id']as$key)m4c_expect(array_key_exists($key,$fixture['user'])&&(int)$fixture['user'][$key]===0,'M4C_FIXTURE_NO_SPREAD_OR_AGENT_BINDING');
    $events=m4cb_events(audit_db());$facts=m4cb_snapshot(audit_db());
    foreach(['eb_store_cart','eb_store_order','eb_store_order_invoice','eb_store_order_cart_info','eb_marketplace_order_idempotency','eb_marketplace_stock_movement']as$table)
        m4c_expect($facts[$table]===[],'M4C_PREPARE_NEVER_CREATES_CART_OR_ORDER');
    $private=['schema'=>1,'scope'=>'MS4_CUSTOMER_PRIVATE_FIXTURE','run_id'=>$source['run_id'],'source_sha'=>$source['source_sha'],
        'freeze_sha256'=>$source['freeze_sha256'],'prepare_container_id'=>$source['container_id'],
        'prepare_native_created_sha256'=>$source['native_created_sha256'],'prepare_source_binding_sha256'=>$source['source_binding_sha256'],
        'environment'=>$source['environment'],'self_operated_mer_id'=>$source['self_operated_mer_id'],
        'account'=>$fixture['user']['account'],'password'=>$password,'fixture'=>$fixture];
    $proof=m4cb_private_write($private);unset($password,$private);audit_guard_assert();
    return $proof+['fixture_counts'=>['customers'=>1,'merchants'=>1,'products'=>2,'skus'=>3,'cart_rows'=>0,'orders'=>0],
        'production_password_reset'=>true,'event_preconditions'=>$events,'real_enabled_configuration'=>true,
        'self_operated_identity_verified'=>true,'HTTP_executed'=>false,'order_creation_executed'=>false];
}

function m4cb_fixture_load(array $source): array
{
    $stat=m4cb_private_meta();$raw=file_get_contents(M4CB_FIXTURE);m4c_expect(is_string($raw)&&strlen($raw)===$stat['size'],'M4C_PRIVATE_FIXTURE_COMPLETE_READ');
    $value=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$path='/run/audit/evidence/ms4-customer-prepare.json';
    m4c_expect(is_file($path)&&!is_link($path)&&filesize($path)<=1048576,'M4C_ACTUAL_PUBLIC_PREPARE_RECEIPT');
    $publicRaw=file_get_contents($path);$prepare=json_decode($publicRaw,true,64,JSON_THROW_ON_ERROR);
    $privateEnvironment=$value['environment'];$actualEnvironment=$source['environment'];
    ksort($privateEnvironment,SORT_STRING);ksort($actualEnvironment,SORT_STRING);
    m4c_expect(is_array($value)&&$value['schema']===1&&$value['scope']==='MS4_CUSTOMER_PRIVATE_FIXTURE'
        &&$value['run_id']===$source['run_id']&&$value['source_sha']===$source['source_sha']&&$value['freeze_sha256']===$source['freeze_sha256']
        &&$privateEnvironment===$actualEnvironment&&$value['self_operated_mer_id']===$source['self_operated_mer_id']
        &&$prepare['schema']===1&&$prepare['scope']==='CUSTOMER_HTTP_FIXTURE_PREPARATION_ONLY'
        &&$prepare['status']==='VERIFIED_CUSTOMER_HTTP_FIXTURE_PREPARED_ONLY'&&$prepare['native_exit']===0
        &&$prepare['phase']==='final_source'&&$prepare['full_source_unchanged']===true
        &&$prepare['run_id']===$source['run_id']&&$prepare['source_sha']===$source['source_sha']&&$prepare['freeze_sha256']===$source['freeze_sha256']
        &&$prepare['container_id']===$value['prepare_container_id']&&$prepare['native_created_sha256']===$value['prepare_native_created_sha256']
        &&$prepare['source_binding_sha256']===$value['prepare_source_binding_sha256']
        &&$prepare['business']['fixture_sha256_prefix']===substr(hash('sha256',$raw),0,12)
        &&$prepare['business']['fixture_bytes']===strlen($raw),'M4C_PRIVATE_FIXTURE_BOUND_TO_ACTUAL_PREPARE_RESULT');
    m4c_expect(is_string($value['account'])&&$value['account']===$value['fixture']['user']['account']
        &&is_string($value['password'])&&strlen($value['password'])===24,'M4C_PRIVATE_REAL_LOGIN_MATERIAL');
    return ['private'=>$value,'sha256'=>hash('sha256',$raw),'stat'=>array_intersect_key($stat,array_fill_keys(['dev','ino','uid','mode','size'],true)),
        'prefix'=>substr(hash('sha256',$raw),0,12),
        'prepare_receipt_sha256'=>hash('sha256',$publicRaw)];
}
function m4cb_http(string $method,string $path,array $headers=[],mixed $body=null): array
{
    m4c_expect(in_array($method,['GET','POST'],true)&&str_starts_with($path,'/api/'),'M4C_FIXED_NORMAL_API_TARGET');
    $response=audit_http($method,'http://audit-app:8000'.$path,$headers,$body);
    $GLOBALS['m4c_http_call_count']=($GLOBALS['m4c_http_call_count']??0)+1;
    m4c_expect($response['http']===200&&is_array($response['json'])&&($response['json']['status']??null)===200
        &&is_array($response['json']['data']??null),'M4C_ACTUAL_NORMAL_HTTP_JSON_SUCCESS');return $response['json']['data'];
}
function m4cb_wire_created(array $data,string $key,?string $number=null): string
{
    m4c_expect(($data['status']??null)==='SUCCESS'&&is_array($data['result']??null),'M4C_REAL_CREATE_RESPONSE_WRAPPER');
    $result=$data['result'];m4c_expect(($result['key']??null)===$key&&is_string($result['orderId']??null)&&$result['orderId']!==''
        &&($number===null||$result['orderId']===$number),'M4C_REAL_RETURNED_BUSINESS_ORDER_NUMBER');return $result['orderId'];
}
function m4cb_lookup_result(array $data,string $key,?string $number): void
{
    m4c_expect(($data['key']??null)===$key&&($data['state']??null)===($number===null?'not_found':'created')
        &&($number===null?!array_key_exists('orderId',$data):($data['orderId']??null)===$number),'M4C_REAL_OWNER_CONFIRMATION_RESULT');
}
function m4cb_public_money(array $data,string $field,string $expected): void
{
    m4c_expect(isset($data[$field])&&is_string($data[$field])&&$data[$field]===$expected,'M4C_INDEPENDENT_PUBLIC_DECIMAL_VALUE');
}
function m4cb_cart_stats(array $rows,array $fixture,int $from,int $through): void
{
    m4c_expect(count($rows)===3,'M4C_THREE_REAL_CART_STAT_ROWS');$expected=[];
    foreach([...$fixture['selected'],$fixture['unselected']]as$line)$expected[]=$line['product_id'].':'.$line['qty'];$actual=[];
    foreach($rows as$row){m4c_expect($row['type']==='cart'&&(int)$row['uid']===(int)$fixture['user']['uid']
        &&(int)$row['add_time']>=$from&&(int)$row['add_time']<=$through,'M4C_REAL_CART_STAT_IDENTITY_AND_TIME');
        foreach(['visit_num','order_num','pay_num','pay_uid','refund_num','collect_num']as$field)m4c_expect((int)$row[$field]===0,'M4C_CART_STAT_NO_OTHER_ACTION');
        foreach(['pay_price','cost_price','refund_price']as$field)m4c_expect($row[$field]==='0.00','M4C_CART_STAT_NO_FINANCIAL_EFFECT');
        $actual[]=(int)$row['product_id'].':'.(int)$row['cart_num'];
    }sort($actual,SORT_STRING);sort($expected,SORT_STRING);m4c_expect($actual===$expected,'M4C_EXACT_CART_STAT_MULTISET');
}
function m4cb_saved(PDO $pdo,array $fixture,array $request,string $idem,array $frame,array $answers,string $number,array $before): array
{
    $uid=(int)$fixture['user']['uid'];$mer=(int)$fixture['merchant']['mer_id'];
    $connection=Db::connect();$parentPdo=$connection->getPdo();
    if(!$parentPdo instanceof PDO){$connection->query('SELECT 1',[],true);$parentPdo=$connection->getPdo();}
    m4c_expect($parentPdo instanceof PDO&&!$pdo->inTransaction()&&!$parentPdo->inTransaction()
        &&$connection===Db::connect()&&$parentPdo===Db::connect()->getPdo()&&$pdo!==$parentPdo,'M4C_NATIVE_INDEPENDENT_COMMITTED_ORACLE');
    $orders=m4cb_rows($pdo,'SELECT * FROM `eb_store_order` WHERE `uid` = ? AND `order_id` = ?',[$uid,$number]);
    m4c_expect(count($orders)===1,'M4C_EXACTLY_ONE_REAL_SAVED_ORDER');$order=$orders[0];$id=(int)$order['id'];
    m4c_expect($id>0&&(int)$order['uid']===$uid&&(int)$order['pay_uid']===$uid&&(int)$order['mer_id']===$mer
        &&(int)$order['marketplace_trade_version']===1&&$order['unique']===$request['confirm_key']&&$order['order_id']===$number
        &&json_decode($order['cart_id'],true,32,JSON_THROW_ON_ERROR)===$request['cart_refs'],'M4C_SAVED_NATIVE_ORDER_IDENTITY');
    foreach(['paid','is_cancel','is_gift','status','refund_status']as$field)m4c_expect((int)$order[$field]===0,'M4C_SAVED_ORDER_STILL_UNPAID_AND_UNFULFILLED');
    foreach(['total_price'=>'70.55','total_postage'=>'8.01','pay_postage'=>'8.01','pay_price'=>'78.56','cost'=>'24.25']as$field=>$value)
        m4c_expect($order[$field]===$value,'M4C_INDEPENDENT_SAVED_DECIMAL_TOTAL');
    $address=$fixture['address'];m4c_expect((int)$order['total_num']===3&&(int)$order['shipping_type']===1&&$order['pay_type']===''
        &&$order['channel_type']==='h5'&&$order['mark']===$request['options']['mark']&&$order['gift_mark']===$request['options']['gift_mark']
        &&$order['real_name']===$address['real_name']&&$order['user_phone']===$address['phone']
        &&$order['user_address']===$address['province'].' '.$address['city'].' '.$address['district'].' '.$address['detail']
        &&$order['merchant_name_snapshot']===$fixture['merchant']['name']
        &&$order['merchant_after_sale_contact_snapshot']===$fixture['merchant']['after_sale_contact'],'M4C_SAVED_INPUT_AND_SHOP_SNAPSHOTS');
    $moves=m4cb_rows($pdo,'SELECT * FROM `eb_marketplace_stock_movement` WHERE `order_id` = ? ORDER BY `sku_id`',[$id]);
    $items=m4cb_rows($pdo,'SELECT * FROM `eb_store_order_cart_info` WHERE `oid` = ? ORDER BY `id`',[$id]);
    m4c_expect(count($moves)===2&&count($items)===2,'M4C_TWO_REAL_ITEMS_TWO_REAL_RESERVES');
    $selection=[];foreach($frame['selection']as$line)$selection[$line['cart_ref']]=$line;$movementById=[];$rootState=[];
    foreach($fixture['products']as$productId=>$product)$rootState[$productId]=['stock'=>(int)$product['stock'],'sales'=>(int)$product['sales']];
    foreach($moves as$move){$skuId=(int)$move['sku_id'];$sku=$fixture['skus'][$skuId]??null;$productId=(int)$move['product_id'];$matching=[];
        foreach($selection as$ref=>$line)if($line['sku_id']===$skuId)$matching[(string)$ref]=$line;
        m4c_expect(is_array($sku)&&count($matching)===1,'M4C_RESERVE_HAS_ONE_ACTUAL_SELECTED_SKU');$line=array_values($matching)[0];$qty=$line['qty'];
        $action=['contract_version'=>1,'uid'=>$uid,'mer_id'=>$mer,'order_id'=>$id,'product_id'=>$productId,'sku_id'=>$skuId,
            'sku_type'=>0,'sku_unique'=>$sku['unique'],'qty'=>$qty,'kind'=>'reserve','cart_refs'=>array_map('strval',array_keys($matching))];
        $expected=['mer_id'=>$mer,'order_id'=>$id,'product_id'=>$line['product_id'],'sku_id'=>$skuId,'sku_type'=>0,'sku_unique'=>$sku['unique'],
            'qty'=>$qty,'kind'=>'reserve','idem_key'=>'reserve:'.$id.':'.$skuId,'parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,
            'actor_type'=>'customer','actor_id'=>$uid,'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical($action),true),
            'sku_stock_before'=>$sku['stock'],'sku_stock_after'=>(int)$sku['stock']-$qty,'sku_sales_before'=>$sku['sales'],'sku_sales_after'=>(int)$sku['sales']+$qty,
            'root_stock_before'=>$rootState[$productId]['stock'],'root_stock_after'=>$rootState[$productId]['stock']-$qty,
            'root_sales_before'=>$rootState[$productId]['sales'],'root_sales_after'=>$rootState[$productId]['sales']+$qty,'add_time'=>$order['add_time']];
        m4c_expect(preg_match('/\A[1-9][0-9]*\z/D',(string)$move['id'])===1,'M4C_REAL_RESERVE_PK');
        foreach($expected as$field=>$value)m4c_expect($value===null?$move[$field]===null:(string)$move[$field]===(string)$value,'M4C_COMPLETE_RESERVE_FACT');
        m4c_expect(count($move)===count($expected)+1,'M4C_COMPLETE_RESERVE_FIELD_SET');
        $rootState[$productId]=['stock'=>$expected['root_stock_after'],'sales'=>$expected['root_sales_after']];$movementById[(string)$move['id']]=$move;
    }
    $seen=[];foreach($items as$item){$ref=(string)$item['cart_id'];$line=$selection[$ref]??null;m4c_expect(is_array($line)&&!isset($seen[$ref]),'M4C_EACH_SELECTED_ITEM_ONCE');$seen[$ref]=true;
        $sku=$fixture['skus'][$line['sku_id']];$cart=json_decode($item['cart_info'],true,64,JSON_THROW_ON_ERROR);$lineage=$cart['marketplace']??null;
        m4c_expect((int)$item['uid']===$uid&&(int)$item['mer_id']===$mer&&(int)$item['product_id']===$line['product_id']
            &&(int)$item['cart_num']===$line['qty']&&(int)$item['surplus_num']===$line['qty']&&(int)$item['refund_num']===0
            &&$item['unique']===md5($ref.$id)&&($cart['id']??null)===$ref&&($cart['cart_num']??null)===$line['qty'],'M4C_NATIVE_ITEM_AND_CART_SNAPSHOT');
        m4c_expect(is_array($lineage)&&$lineage['cart_ref']===$ref&&$lineage['sku_id']===$line['sku_id']&&$lineage['sku_type']===0
            &&$lineage['sku_unique']===$sku['unique']&&is_string($lineage['reserve_id'])&&isset($movementById[$lineage['reserve_id']]),'M4C_ITEM_REAL_RESERVE_LINEAGE');
        m4c_expect((int)$cart['productInfo']['id']===$line['product_id']&&(int)$cart['productInfo']['mer_id']===$mer
            &&(int)$cart['productInfo']['attrInfo']['id']===$line['sku_id']&&(int)$cart['productInfo']['attrInfo']['type']===0
            &&(int)$cart['productInfo']['attrInfo']['product_id']===$line['product_id']&&$cart['productInfo']['attrInfo']['unique']===$sku['unique']
            &&$cart['sum_price']===$sku['price']&&$cart['truePrice']===$sku['price']&&$cart['costPrice']===$sku['cost']
            &&$cart['productInfo']['attrInfo']['price']===$sku['price']&&$cart['productInfo']['attrInfo']['cost']===$sku['cost']
            &&$cart['sum_true_price']===bcmul($sku['price'],(string)$line['qty'],2),'M4C_ACTUAL_SELECTED_SKU_PRICE_COST_SNAPSHOT');
        $postage=$line['qty']===2?'5.34':'2.67';m4c_expect($cart['postage_price']===$postage&&$cart['origin_postage_price']===$postage,'M4C_INDEPENDENT_WHOLE_LINE_POSTAGE');
        foreach(['coupon_price','integral_price','one_brokerage','two_brokerage','staff_brokerage','agent_brokerage','division_brokerage']as$field)
            m4c_expect($cart[$field]==='0.00','M4C_NO_DISABLED_RIGHTS_OR_BROKERAGE');
    }m4c_expect(count($seen)===2,'M4C_COMPLETE_SELECTED_ITEM_SET');
    foreach($before['eb_store_product']as$old){$expected=$old;$productId=(int)$old['id'];
        if(isset($rootState[$productId]))foreach($rootState[$productId]as$field=>$value)$expected[$field]=$value;
        $read=m4cb_rows($pdo,'SELECT * FROM `eb_store_product` WHERE `id` = ?',[$old['id']]);m4c_expect(count($read)===1,'M4C_ROOT_RETAINED');m4cb_equal_row($read[0],$expected);
    }
    foreach($before['eb_store_product_attr_value']as$old){$expected=$old;foreach($selection as$line)if((int)$old['id']===$line['sku_id']){
            $expected['stock']=(int)$old['stock']-$line['qty'];$expected['sales']=(int)$old['sales']+$line['qty'];}
        $read=m4cb_rows($pdo,'SELECT * FROM `eb_store_product_attr_value` WHERE `id` = ?',[$old['id']]);m4c_expect(count($read)===1,'M4C_SKU_RETAINED');m4cb_equal_row($read[0],$expected);
    }
    $ledger=m4cb_rows($pdo,'SELECT * FROM `eb_marketplace_order_idempotency` WHERE `uid` = ? AND `idem_key` = ?',[$uid,$idem]);
    $expected=['uid'=>$uid,'mer_id'=>$mer,'idem_key'=>$idem,'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical($request),true),
        'confirmation_ref'=>hash('sha256',$request['confirm_key'],true),'source_kind'=>'persistent','state'=>1,'order_id'=>$id,'post_commit_done'=>1,
        'cancel_at'=>0,'add_time'=>$order['add_time']];
    m4c_expect(count($ledger)===1&&count($ledger[0])===12&&json_decode($ledger[0]['cart_refs'],true,32,JSON_THROW_ON_ERROR)===$request['cart_refs'],'M4C_REAL_COMMITTED_LEDGER_AND_REFERENCES');
    foreach($expected as$field=>$value)m4c_expect((string)$ledger[0][$field]===(string)$value,'M4C_COMPLETE_LEDGER_FACT');
    $invoices=m4cb_rows($pdo,'SELECT * FROM `eb_store_order_invoice` WHERE `order_id` = ?',[$id]);
    m4c_expect(count($invoices)===1&&(int)$invoices[0]['uid']===$uid&&$invoices[0]['category']==='order'
        &&(int)$invoices[0]['id']>0&&(int)$invoices[0]['order_id']===$id&&(int)$invoices[0]['add_time']===(int)$order['add_time']
        &&(int)$invoices[0]['invoice_id']===(int)$fixture['invoice']['id'],'M4C_REAL_ORIGINAL_INVOICE_SNAPSHOT');
    foreach(['is_pay','is_refund','is_invoice']as$field)m4c_expect((int)$invoices[0][$field]===0,'M4C_INVOICE_STILL_UNPAID_UNREFUNDED_UNISSUED');
    foreach(['header_type','type','name','duty_number','drawer_phone','email','tell','address','bank','card_number']as$field)
        m4c_expect((string)$invoices[0][$field]===(string)$fixture['invoice'][$field],'M4C_COMPLETE_ORIGINAL_INVOICE_HEADING');
    $stored=json_decode($order['custom_form'],true,32,JSON_THROW_ON_ERROR);$expectedForms=$frame['custom_form'];
    foreach($expectedForms as$i=>&$group){foreach($group['fields']as$j=>&$field)$field['value']=$answers[$i]['values'][$j]['value'];unset($field);}unset($group);
    m4c_expect($stored===$expectedForms&&count($stored)===2&&$stored[0]['fields'][1]['value']==='0','M4C_COMPLETE_NONEMPTY_SAVED_PRODUCT_FORMS');
    $remaining=m4cb_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `uid` = ? ORDER BY `id`',[$uid]);
    $oldUnselected=array_values(array_filter($before['eb_store_cart'],static fn(array $row):bool=>$row['product_attr_unique']===$fixture['skus'][$fixture['unselected']['sku_id']]['unique']));
    m4c_expect(count($remaining)===count($oldUnselected)&&count($remaining)===1,'M4C_ONLY_UNSELECTED_CART_REMAINS');m4cb_equal_row($remaining[0],$oldUnselected[0]);
    $status=m4cb_rows($pdo,'SELECT * FROM `eb_store_order_status` WHERE `oid` = ?',[$id]);
    m4c_expect(count($status)===1&&(int)$status[0]['oid']===$id&&$status[0]['change_type']==='cache_key_create_order'
        &&$status[0]['change_message']==='订单生成'&&(int)$status[0]['change_time']===(int)$order['add_time'],'M4C_ONE_PRODUCTION_POST_COMMIT_STATUS');
    $logs=m4cb_rows($pdo,'SELECT * FROM `eb_store_product_log` WHERE `type` = ? AND `uid` = ? ORDER BY `id`',['order',$uid]);
    m4c_expect(count($logs)===2,'M4C_TWO_PRODUCTION_ORDER_STAT_ROWS');$actualStats=$expectedStats=[];
    foreach($selection as$line)$expectedStats[]=$line['product_id'].':'.$line['qty'];
    foreach($logs as$log){foreach(['visit_num','cart_num','pay_num','pay_uid','refund_num','collect_num']as$field)m4c_expect((int)$log[$field]===0,'M4C_ORDER_STATS_NO_OTHER_ACTION');
        foreach(['pay_price','cost_price','refund_price']as$field)m4c_expect($log[$field]==='0.00','M4C_ORDER_STATS_NO_FINANCIAL_EFFECT');
        m4c_expect((int)$log['add_time']===(int)$order['add_time'],'M4C_ORDER_STATS_CREATION_TIME');$actualStats[]=(int)$log['product_id'].':'.(int)$log['order_num'];}
    sort($actualStats,SORT_STRING);sort($expectedStats,SORT_STRING);m4c_expect($actualStats===$expectedStats,'M4C_EXACT_ORDER_STAT_MULTISET');
    $after=m4cb_snapshot($pdo);$newCounts=['eb_store_order'=>1,'eb_store_order_invoice'=>1,'eb_store_order_cart_info'=>2,
        'eb_marketplace_order_idempotency'=>1,'eb_marketplace_stock_movement'=>2,'eb_store_order_status'=>1];
    foreach($newCounts as$table=>$count)m4c_expect($before[$table]===[]&&count($after[$table])===$count,'M4C_EXACT_ONLY_NEW_ORDER_EFFECT_COUNTS');
    m4c_expect(count($after['eb_store_product_log'])===count($before['eb_store_product_log'])+2,'M4C_ONLY_TWO_NEW_ORDER_STATS');
    m4c_expect(count($after['eb_store_product'])===count($before['eb_store_product'])
        &&count($after['eb_store_product_attr_value'])===count($before['eb_store_product_attr_value'])
        &&$after['eb_store_cart']===$remaining,'M4C_EXISTING_PRODUCT_AND_REMAINING_CART_SET');
    $oldLogs=array_values(array_filter($after['eb_store_product_log'],static fn(array $row):bool=>$row['type']!=='order'));
    m4c_expect($oldLogs===$before['eb_store_product_log'],'M4C_PRIOR_CART_STATS_FULLY_PRESERVED');
    m4cb_preserved($before,$after,$uid,[...array_keys($newCounts),'eb_store_product','eb_store_product_attr_value','eb_store_cart','eb_store_product_log']);
    return $after;
}

function m4c_verify_http(array $source): array
{
    m4cb_phase('business_verify_fixture');m4cb_guard_readonly();$app=$GLOBALS['m4c_application']??null;
    m4c_expect($app instanceof \think\App&&app()===$app,'M4C_VERIFY_USES_ROOT_BOOTSTRAPPED_REAL_APP');
    $loaded=m4cb_fixture_load($source);$private=$loaded['private'];$fixture=$private['fixture'];$uid=(int)$fixture['user']['uid'];$mer=(int)$fixture['merchant']['mer_id'];
    $pdo=audit_db();m4c_expect(!$pdo->inTransaction()&&$pdo!==Db::connect()->getPdo(),'M4C_REAL_SECOND_NATIVE_SQL_CONNECTION');
    $events=m4cb_events($pdo);$before=m4cb_snapshot($pdo);
    $actualUser=m4cb_rows($pdo,'SELECT * FROM `eb_user` WHERE `uid` = ?',[$uid]);m4c_expect(count($actualUser)===1,'M4C_PREPARED_OWNER_ACTUALLY_EXISTS');m4cb_equal_row($actualUser[0],$fixture['user']);
    $started=time();$GLOBALS['m4c_http_call_count']=0;m4cb_phase('business_http_login');
    $login=m4cb_http('POST','/api/login',[],['account'=>$private['account'],'password'=>$private['password'],'spread'=>0,'agent_id'=>0]);
    m4c_expect(is_string($login['token']??null)&&$login['token']!==''&&is_int($login['expires_time']??null)&&$login['expires_time']>time(),'M4C_REAL_LOGIN_TOKEN_AND_EXPIRY');
    $headers=['Authori-zation'=>'Bearer '.$login['token']];unset($login,$private['password'],$loaded['private']['password']);
    $afterLogin=m4cb_snapshot($pdo);m4cb_preserved($before,$afterLogin,$uid,[],true);
    m4cb_phase('business_http_cart_producers');$refs=[];
    foreach([...$fixture['selected'],$fixture['unselected']]as$line){$sku=$fixture['skus'][$line['sku_id']];
        $added=m4cb_http('POST','/api/cart/add',$headers,['productId'=>$line['product_id'],'cartNum'=>$line['qty'],'uniqueId'=>$sku['unique'],
            'new'=>0,'is_new'=>0,'combinationId'=>0,'secKillId'=>0,'bargainId'=>0,'advanceId'=>0,'pinkId'=>0]);
        $ref=MarketplaceOrderInput::reference($added['cartId']??null,'persistent');m4c_expect(!in_array($ref,$refs,true),'M4C_THREE_DISTINCT_REAL_CART_REFERENCES');$refs[]=$ref;
    }
    $listed=m4cb_http('GET','/api/cart/list?page=1&limit=20',$headers);
    m4c_expect(count($listed['valid']??[])===3&&($listed['invalid']??null)===[]&&count($listed['groups']??[])===1,'M4C_REAL_THREE_VALID_ROWS_ONE_STORE_GROUP');
    $group=$listed['groups'][0];m4c_expect(($group['mer_id']??null)===$mer&&($group['store']['mer_id']??null)===$mer
        &&($group['store']['name']??null)===$fixture['merchant']['name']&&count($group['rows']??[])===3,'M4C_ACTUAL_PUBLIC_STORE_GROUP');
    $listedRefs=[];foreach($listed['valid']as$row){m4c_expect(is_string($row['id']??null),'M4C_PUBLIC_CART_REFERENCE_STAYS_STRING');$listedRefs[]=$row['id'];}
    $expectedRefs=$refs;$groupRefs=$group['cart_refs'];sort($listedRefs,SORT_STRING);sort($expectedRefs,SORT_STRING);sort($groupRefs,SORT_STRING);
    m4c_expect($listedRefs===$expectedRefs&&$groupRefs===$expectedRefs,'M4C_EXACT_GROUPED_PUBLIC_REFERENCE_SET');
    $cartFacts=m4cb_snapshot($pdo);m4cb_preserved($afterLogin,$cartFacts,$uid,['eb_store_cart','eb_store_product_log']);
    m4c_expect(count($cartFacts['eb_store_cart'])===3,'M4C_REAL_PRODUCER_SAVED_THREE_CARTS');
    foreach([...$fixture['selected'],$fixture['unselected']]as$i=>$line){$row=m4cb_rows($pdo,'SELECT * FROM `eb_store_cart` WHERE `id` = ?',[$refs[$i]]);
        m4c_expect(count($row)===1&&(int)$row[0]['uid']===$uid&&(int)$row[0]['product_id']===$line['product_id']
            &&$row[0]['product_attr_unique']===$fixture['skus'][$line['sku_id']]['unique']&&(int)$row[0]['cart_num']===$line['qty']
            &&(int)$row[0]['is_pay']===0&&(int)$row[0]['is_del']===0&&(int)$row[0]['is_new']===0&&(int)$row[0]['status']===1,'M4C_ACTUAL_CART_PRODUCER_SELECTION');
    }m4cb_cart_stats($cartFacts['eb_store_product_log'],$fixture,$started,time());
    $selectedRefs=MarketplaceOrderInput::references(implode(',',array_slice($refs,0,2)),'persistent');$csv=implode(',',$selectedRefs);
    $address=(int)$fixture['address']['id'];m4cb_phase('business_http_confirm_and_quote');
    $confirmation=m4cb_http('POST','/api/order/confirm',$headers,['cartId'=>$csv,'mer_id'=>$mer,'addressId'=>$address,'new'=>0,'shipping_type'=>1,'is_gift'=>0]);
    m4c_expect(($confirmation['marketplace_confirmation_version']??null)===1&&($confirmation['marketplace_create_enabled']??null)===true
        &&($confirmation['mer_id']??null)===$mer&&($confirmation['source_kind']??null)==='persistent'
        &&is_string($confirmation['orderKey']??null),'M4C_REAL_ENABLED_V1_CONFIRMATION');$key=MarketplaceOrderInput::confirmationKey($confirmation['orderKey']);
    m4cb_public_money($confirmation['priceGroup'],'totalPrice','70.55');
    $computedBody=['addressId'=>$address,'shipping_type'=>1,'payType'=>'','mer_id'=>$mer,'couponId'=>0,'useIntegral'=>0];
    $computed=m4cb_http('POST','/api/order/computed/'.rawurlencode($key),$headers,$computedBody);
    m4c_expect(($computed['status']??null)==='NONE'&&($computed['result']['marketplace_create_enabled']??null)===true,'M4C_REAL_ENABLED_NORMAL_COMPUTED');
    m4cb_public_money($computed['result'],'pay_price','78.56');m4cb_public_money($computed['result'],'pay_postage','8.01');
    $cached=\crmeb\services\CacheService::get('user_order_'.$uid.$key);m4c_expect(is_array($cached),'M4C_ACTUAL_HTTP_PRODUCED_PRIVATE_FRAME');
    $frame=MarketplaceCartSelection::frame($cached,$uid);m4c_expect($frame['mer_id']===$mer&&$frame['source_kind']==='persistent'
        &&$frame['cart_refs']===$selectedRefs&&count($frame['selection'])===2&&count($frame['custom_form'])===2,'M4C_REAL_HTTP_FRAME_MATCHES_SELECTED_CARTS');
    $answers=[];foreach($frame['custom_form']as$definition){$product=$fixture['products'][$definition['product_id']]??null;
        m4c_expect(is_array($product)&&$definition['product_name']===$product['store_name'],'M4C_REAL_FORM_BELONGS_TO_PREPARED_PRODUCT');
        $raw=json_decode($product['custom_form'],true,32,JSON_THROW_ON_ERROR);$fields=[];$values=[];
        foreach($raw as$i=>$field){$fields[]=['field_index'=>$i]+$field;$values[]=['field_index'=>$i,'value'=>$field['label']==='number'?'0':'正常HTTP填写'.$definition['product_name']];}
        m4c_expect($definition['fields']===$fields&&$definition['definition_sha256']===hash('sha256',MarketplaceOrderInput::canonical([
            'product_id'=>$definition['product_id'],'fields'=>$fields])),'M4C_INDEPENDENT_NATIVE_PRODUCT_FORM_DEFINITION');
        $answers[]=['product_id'=>$definition['product_id'],'definition_sha256'=>$definition['definition_sha256'],'values'=>$values];
    }
    $lookupHeaders=$headers+['Content-Type'=>'application/json'];m4cb_phase('business_http_lookup_before');
    m4cb_lookup_result(m4cb_http('POST','/api/order/confirmation/'.rawurlencode($key),$lookupHeaders,'{}'),$key,null);
    $beforeCreate=m4cb_snapshot($pdo);m4cb_preserved($cartFacts,$beforeCreate,$uid);
    $raw=['cartId'=>$csv,'mer_id'=>$mer,'new'=>0,'idem_key'=>'confirm:'.$key,'addressId'=>$address,'invoice_id'=>(int)$fixture['invoice']['id'],
        'payType'=>'','mark'=>'普通HTTP下单','gift_mark'=>'普通HTTP文本','real_name'=>'','phone'=>'','shipping_type'=>1,'is_gift'=>0,'store_id'=>0,
        'couponId'=>0,'useIntegral'=>false,'combinationId'=>0,'pinkId'=>0,'seckill_id'=>0,'bargainId'=>0,'advanceId'=>0,'custom_form'=>$answers];
    $wire=m4cb_json($raw);$input=MarketplaceOrderInput::create($uid,$key,$raw);m4cb_phase('business_http_production_create');
    $number=m4cb_wire_created(m4cb_http('POST','/api/order/create/'.rawurlencode($key),$headers,$raw),$key);
    m4cb_phase('business_independent_created_readback');m4cb_guard_readonly();
    $createdFacts=m4cb_saved($pdo,$fixture,$input['request'],$input['idem_key'],$frame,$input['request']['options']['custom_form'],$number,$beforeCreate);
    m4cb_phase('business_http_normal_same_request_replay');m4c_expect(m4cb_json($raw)===$wire,'M4C_COMPLETE_ORIGINAL_REQUEST_BYTES_REUSED');
    m4cb_wire_created(m4cb_http('POST','/api/order/create/'.rawurlencode($key),$headers,$raw),$key,$number);
    $replayFacts=m4cb_snapshot($pdo);m4cb_preserved($createdFacts,$replayFacts,$uid);
    m4cb_phase('business_http_lookup_created');m4cb_lookup_result(m4cb_http('POST','/api/order/confirmation/'.rawurlencode($key),$lookupHeaders,'{}'),$key,$number);
    $lookupFacts=m4cb_snapshot($pdo);m4cb_preserved($replayFacts,$lookupFacts,$uid);
    m4cb_phase('business_http_saved_computed');$saved=m4cb_http('POST','/api/order/computed/'.rawurlencode($key),$headers,$computedBody);
    m4c_expect(($saved['status']??null)==='SUCCESS'&&is_array($saved['result']??null),'M4C_REAL_SAVED_COMPUTED_WRAPPER');
    m4cb_lookup_result($saved['result'],$key,$number);$final=m4cb_snapshot($pdo);m4cb_preserved($lookupFacts,$final,$uid);
    m4c_expect($GLOBALS['m4c_http_call_count']===12&&m4cb_json($raw)===$wire,'M4C_EXACT_TWELVE_NORMAL_HTTP_CALLS');
    m4cb_events($pdo);$still=m4cb_private_meta();m4c_expect(hash_equals($loaded['sha256'],hash_file('sha256',M4CB_FIXTURE))
        &&array_intersect_key($still,array_fill_keys(['dev','ino','uid','mode','size'],true))===$loaded['stat'],'M4C_PRIVATE_FIXTURE_REMAINS_ORIGINAL');
    unset($headers,$lookupHeaders,$raw,$wire,$cached,$private,$fixture,$login);m4cb_guard_readonly();
    return ['http_call_count'=>12,'fixture_sha256_prefix'=>$loaded['prefix'],'prepare_receipt_sha256'=>$loaded['prepare_receipt_sha256'],
        'fact_table_count'=>25,'saved_counts'=>['orders'=>1,'items'=>2,'reserves'=>2,'invoices'=>1,'ledger'=>1,'unselected_cart'=>1,
            'cart_stat_rows'=>3,'order_stat_rows'=>2,'creation_status_rows'=>1],
        'real_credential_login'=>true,'real_request_uid_and_production_handlers'=>true,'real_enabled_configuration'=>true,
        'independent_committed_sql_verified'=>true,'same_request_replay_unchanged'=>true,'owner_lookup_unchanged'=>true,
        'saved_computed_unchanged'=>true,'nonempty_forms_and_invoice_verified'=>true,'whole_line_postage_verified'=>true,
        'only_owner_auth_fields_changed'=>true,'order_still_unpaid'=>true,'event_preconditions'=>$events,
        'private_facts_sha256_prefix'=>substr(hash('sha256',serialize($final)),0,12),
        'payment_or_shipping_executed'=>false,'customer_DOM_acceptance'=>'NOT RUN','full_acceptance'=>'INCOMPLETE'];
}
