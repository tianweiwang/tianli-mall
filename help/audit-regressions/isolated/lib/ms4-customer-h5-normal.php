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
    $before=m4cb_snapshot($pdo);$auxiliaryBefore=[];
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
        array_map('m4cb_text_row',[...$before[$table],...($added[$table]??[])]),'M4C_H5_EXACT_ALL_TWENTY_FIVE_PREPARATION_ROWS');
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
    $baseline=$identity+['scope'=>'NORMAL_CUSTOMER_H5_SQL_BASELINE','facts'=>$after,
        'auxiliary_rows'=>$configuration['auxiliary_after'],'facts_sha256'=>hash('sha256',m4cb_json($after)),
        'auxiliary_sha256'=>hash('sha256',m4cb_json($configuration['auxiliary_after']))];
    $oracle=$identity+['scope'=>'NORMAL_CUSTOMER_H5_PRIVATE_ORACLE','fixture'=>$fixture,'second_store'=>$second,
        'public_stores'=>$stores,'configuration_rows'=>$configuration['configuration_rows'],'configuration_values'=>$configuration['fixed_values'],
        'auxiliary_rows'=>$configuration['auxiliary_after'],
        'ui_intent'=>['cart_lines'=>[...$base['selected'],$secondLine],'checkout_mer_id'=>(int)$base['merchant']['mer_id'],
            'selected_lines'=>$base['selected'],'unselected_cart_line'=>$secondLine,'preserved_same_store_unselected_sku'=>$base['unselected'],
            'address_id'=>(int)$base['address']['id'],'invoice_id'=>(int)$base['invoice']['id'],
            'custom_form_inputs'=>$formInputs,'mark'=>'普通顾客页面下单','target_order_state'=>'unpaid']];
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
    $sealed['customer-actors']=['sha256_prefix'=>$actorProof['prefix'],'bytes'=>$actorProof['bytes']];
    unset($private,$loaded,$again,$actors,$value);
    return ['fixture_counts'=>['customers'=>1,'merchants'=>2,'products'=>3,'skus'=>4,'cart_rows'=>0,'orders'=>0],
        'configuration_keys'=>array_keys($configuration['fixed_values']),'language_counts'=>['types'=>1,'countries'=>1,'user_codes'=>1179],
        'theme_count'=>1,'business_table_count'=>25,'auxiliary_table_count'=>7,'sealed_files'=>$sealed,
        'base_fixture_preserved'=>true,'native_rows_and_relationships_verified'=>true,'production_configuration_published_and_read_back'=>true,
        'theme_service_saved_enabled_and_read_back'=>true,'language_native_ids_and_full_package_verified'=>true,
        'event_preconditions'=>$events+['product_visit_custom_event_count'=>0],
        'HTTP_executed'=>false,'order_creation_executed'=>false,'credential_login_authenticated'=>false,'HTTP_DOM'=>'NOT RUN'];
}
