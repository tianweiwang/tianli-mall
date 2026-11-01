<?php
declare(strict_types=1);
// Bounded normal preview actor. SOURCE READY ONLY until Root records the actual native execution.
require '/workspace/help/preview/common.php';
require_once '/workspace/crmeb/vendor/autoload.php';
$frpFile=null;$frpState=null;$frpStep='input';$frpFailure=null;$rppResponse=null;$rppRequest=null;$rppServerBefore=$_SERVER;$rppApp=null;
function frp_check(bool $ok, string $code): void
{ if (!$ok) { $GLOBALS['frpFailure'] = $code; throw new RuntimeException('PREVIEW_REFUND_CHECK_FAILED'); } }
function frp_id(mixed $v): int
{ frp_check((is_int($v)||is_string($v))&&preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$v)===1&&(int)$v<=2147483647,'positive_saved_id'); return (int)$v; }
function frp_money(mixed $v): string
{
    frp_check(is_string($v)||is_int($v)||(is_float($v)&&is_finite($v)),'money_type');
    frp_check(preg_match('/\A(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/D',(string)$v,$m)===1,'money_decimal');
    return $m[1].'.'.str_pad($m[2]??'',2,'0');
}
function frp_save(): void
{
    $f=$GLOBALS['frpFile']; if (!is_resource($f)||!is_array($GLOBALS['frpState'])) return;
    $raw=json_encode($GLOBALS['frpState'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    frp_check(rewind($f)&&ftruncate($f,0)&&fwrite($f,$raw)===strlen($raw)&&fflush($f)&&fsync($f),'receipt_complete_write');
}
function frp_http(string $role,string $method,string $path,?array $body=null,?string $token=null,bool $credentialBody=false): array
{
    $GLOBALS['frpStep']=$role.' '.$method.' '.$path;
    frp_check(in_array($role,['admin','merchant','customer'],true)&&in_array($method,['GET','POST','PUT'],true)
        &&str_starts_with($path,'/')&&!str_contains($path,'://')&&!str_contains($path,"\r")&&!str_contains($path,"\n"),'fixed_http_request');
    $index=count($GLOBALS['frpState']['operations']);
    $op=['role'=>$role,'method'=>$method,'path'=>$path,'state'=>'INTENT_SAVED','at'=>time()];
    // Authentication bodies, passwords and tokens are never serialized or hashed into a receipt.
    if ($method!=='GET') $op['body']=$credentialBody?['credentials'=>'RAM_ONLY_NOT_RECORDED']:$body;
    $GLOBALS['frpState']['operations'][]=$op; frp_save();
    $headers=['Accept: application/json','Cb-lang: zh-CN'];
    if($token!==null)$headers[]=($role==='admin'?'Authori-zation':'Authorization').': Bearer '.$token;
    if($body!==null)$headers[]='Content-Type: application/json';
    $curl=curl_init('http://preview-web:8080'.$path);frp_check($curl!==false,'http_handle');
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'']);
    if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body===[]?'{}':json_encode($body,JSON_THROW_ON_ERROR));
    $raw=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$errno=curl_errno($curl);curl_close($curl);
    $json=is_string($raw)?json_decode($raw,true):null;$business=is_array($json)&&is_int($json['status']??null)?$json['status']:null;
    $GLOBALS['frpState']['operations'][$index]+= ['http'=>$http,'business_status'=>$business,'transport_errno'=>$errno];
    $GLOBALS['frpState']['operations'][$index]['state']=($errno===0&&$http===200&&$business===200)?'RESPONSE_200':'RESULT_UNCONFIRMED_STOP';frp_save();
    frp_check($errno===0&&$http===200&&$business===200&&is_array($json['data']??null),'normal_http_response');
    return $json['data'];
}
function frp_login(string $role,array $credentials): string
{
    frp_check(is_string($credentials['account']??null)&&is_string($credentials['password']??null),'private_credentials_in_RAM');
    $body=['account'=>$credentials['account'],$role==='admin'?'pwd':'password'=>$credentials['password']];
    if($role==='customer')$body+=['spread'=>0,'agent_id'=>0];
    $reply=frp_http($role,'POST',['admin'=>'/adminapi/login','merchant'=>'/merchantapi/login','customer'=>'/api/login'][$role],$body,null,true);
    frp_check(is_string($reply['token']??null)&&$reply['token']!=='','actual_login_token');return $reply['token'];
}
function frp_rows(PDO $db,string $sql,array $parameters=[]): array
{ $s=$db->prepare($sql);frp_check($s->execute($parameters),'readonly_query');return $s->fetchAll(PDO::FETCH_ASSOC); }
function frp_ro(PDO $db,Closure $read): array
{
    frp_check(!$db->inTransaction(),'readonly_outside');$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
    try{$v=$read();frp_check($db->commit(),'readonly_commit');return $v;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function frp_digest(array $v): array
{ $out=[];foreach($v as $k=>$rows)$out[$k]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];return $out; }
function frp_stock(PDO $db,array $p): array
{
    return frp_ro($db,static fn():array=>['product'=>frp_rows($db,'SELECT * FROM eb_store_product WHERE id=?',[$p['product_id']]),
        'sku'=>frp_rows($db,'SELECT * FROM eb_store_product_attr_value WHERE id=?',[$p['sku_id']])]);
}
function frp_facts(PDO $db,int $root,int $refund,array $p): array
{
    return frp_ro($db,static fn():array=>[
        'orders'=>frp_rows($db,'SELECT * FROM eb_store_order WHERE id=? OR pid=? ORDER BY id',[$root,$root]),
        'items'=>frp_rows($db,'SELECT * FROM eb_store_order_cart_info WHERE oid IN (SELECT id FROM eb_store_order WHERE id=? OR pid=?) ORDER BY id',[$root,$root]),
        'payment_registry'=>frp_rows($db,'SELECT * FROM eb_marketplace_payment_registry WHERE order_id=? ORDER BY order_id',[$root]),
        'payment_attempts'=>frp_rows($db,'SELECT * FROM eb_marketplace_payment_attempt WHERE order_id=? ORDER BY id',[$root]),
        'movements'=>frp_rows($db,'SELECT * FROM eb_marketplace_stock_movement WHERE order_id=? ORDER BY id',[$root]),
        'fulfillment_operations'=>frp_rows($db,'SELECT * FROM eb_marketplace_fulfillment_operation WHERE root_order_id=? ORDER BY id',[$root]),
        'fulfillment_ranges'=>frp_rows($db,'SELECT * FROM eb_marketplace_fulfillment_range WHERE root_order_id=? ORDER BY id',[$root]),
        'product'=>frp_rows($db,'SELECT * FROM eb_store_product WHERE id=?',[$p['product_id']]),
        'sku'=>frp_rows($db,'SELECT * FROM eb_store_product_attr_value WHERE id=?',[$p['sku_id']]),
        'refund'=>frp_rows($db,'SELECT * FROM eb_store_order_refund WHERE id=?',[$refund]),
        'refund_actions'=>frp_rows($db,'SELECT * FROM eb_marketplace_aftersale_action WHERE refund_id=? ORDER BY id',[$refund]),
        'refund_attempts'=>frp_rows($db,'SELECT * FROM eb_marketplace_refund_attempt WHERE refund_id=? ORDER BY id',[$refund])]);
}
function frp_view(array $v,int $root,int $refund,string $number,string $kind,string $amount,bool $final): void
{
    frp_check(($v['schema']??null)===2&&($v['scope']??null)==='marketplace_aftersale_v1'&&($v['id']??null)===$refund
        &&($v['root_order_id']??null)===$root&&($v['refund_no']??null)===$number&&($v['operation_kind']??null)===$kind
        &&($v['is_test_payment']??null)===true&&($v['requested_amount']??null)===$amount,'three_role_saved_refund_identity');
    if($final)frp_check(($v['finance_state']??null)==='SUCCEEDED'&&($v['business_state']??null)==='COMPLETED'
        &&($v['confirmed_amount']??null)===$amount&&($v['held_amount']??null)==='0.00','saved_completed_refund_amount');
}

function rpp_response(\think\Response $response):array
{
    $content=$response->getContent();$json=json_decode($content,true,64,JSON_THROW_ON_ERROR);
    frp_check($response->getCode()===200&&is_array($json)&&array_keys($json)===['status','msg','data']
        &&$json['status']===200&&is_string($json['msg'])&&is_array($json['data']),'real_controller_response_200');
    return $json['data']; // Never save error bodies, response headers, or authentication material.
}
function rpp_factory(\think\App $app,array $fixed):void
{
    $c=\think\facade\Db::connect();$pdo=$c->getPdo();
    frp_check(app()===$app&&$app->initialized()&&$app->make(\crmeb\services\pay\marketplace\PaymentAdapter::class)===$fixed['driver']
        &&$app->make(\crmeb\services\pay\marketplace\TestPaymentDriver::class)===$fixed['driver']
        &&$app->make(\app\services\marketplace\payment\MarketplaceRefundServices::class)===$fixed['service']
        &&$c===$fixed['connection']&&$pdo===$fixed['pdo']&&$pdo instanceof PDO&&!$pdo->inTransaction()&&$c->transactionDepth()===0
        &&$c->getConfig('deploy')===0&&$c->getConfig('rw_separate')===false
        &&in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true),'same_real_App_driver_service_and_default_connection');
}
function rpp_route(\think\App $app,\app\Request $request,object $rule,int $refund,string $wire):void
{
    frp_check($app->request===$request&&$app->http->getName()==='merchantapi'&&$request->rule()===$rule
        &&$rule->getName()==='merchant.aftersale.test-refund'&&$rule->getOption('merchant_permission')==='aftersale.test_refund'
        &&$request->controller()==='Aftersale'&&$request->action(true)==='testrefund'&&$request->method(true)==='POST'
        &&(string)$request->route('id',null,null)===(string)$refund&&$request->getContent()===$wire,'same_original_Request_route_and_wire');
}
function rpp_dto(array $dto,int $refund,string $key,string $confirmed,bool $final):array
{
    \app\services\marketplace\payment\MarketplaceRefundEvidence::keys($dto,['schema','scope','attempt_id','refund_idem','state','refund_id','amount','currency',
        'finance_state','confirmed_amount','held_amount','is_test_payment','real_refund_enabled']);
    frp_check($dto['schema']===1&&$dto['scope']==='testing_refund_action_v1'&&$dto['refund_id']===$refund&&$dto['refund_idem']===$key
        &&$dto['amount']==='40.30'&&$dto['confirmed_amount']===$confirmed&&$dto['held_amount']===bcsub('40.30',$confirmed,2)
        &&$dto['finance_state']===($final?'SUCCEEDED':'PARTIAL')&&$dto['state']===($final?'applied':'pending')
        &&$dto['currency']==='CNY'&&$dto['is_test_payment']===true&&$dto['real_refund_enabled']===false,'actual_service_progress_DTO');
    \app\services\marketplace\payment\MarketplaceRefundEvidence::u64($dto['attempt_id']);
    return array_intersect_key($dto,array_flip(['attempt_id','refund_id','state','amount','finance_state','confirmed_amount','held_amount']));
}
function rpp_preserved(PDO $db,array $before,array $previous,int $root,int $refund,array $p,string $confirmed,bool $final):array
{
    $now=frp_facts($db,$root,$refund,$p);
    foreach(['orders','items','payment_registry','payment_attempts','movements','fulfillment_operations','fulfillment_ranges','product','sku'] as $name)
        frp_check($now[$name]===$before[$name],'full_original_payment_order_quantity_inventory_and_fulfillment_rows_held');
    frp_check(count($now['refund'])===1&&count($before['refund'])===1&&array_keys($now['refund'][0])===array_keys($before['refund'][0]),'complete_native_refund_row');
    $allowed=['refund_type','refunded_price','refunded_time','marketplace_state','marketplace_finance_state','marketplace_last_action_id'];
    foreach($before['refund'][0] as $field=>$value)if(!in_array($field,$allowed,true))frp_check($now['refund'][0][$field]===$value,'all_other_native_refund_columns_held');
    frp_check($now['refund'][0]['marketplace_finance_state']===($final?'SUCCEEDED':'PARTIAL')
        &&$now['refund'][0]['marketplace_state']===($final?'COMPLETED':'APPROVED')&&$now['refund'][0]['refunded_price']===$confirmed,'native_refund_financial_mirror');
    frp_check(array_slice($now['refund_actions'],0,count($previous['refund_actions']))===$previous['refund_actions'],'old_native_actions_full_typed_prefix_held');
    frp_check(count($now['refund_attempts'])===1,'only_one_real_saved_intent');
    $row=\app\services\marketplace\payment\MarketplaceRefundEvidence::row($now['refund_attempts'][0]);$e=$row['evidence'];
    frp_check($row['contract_version']===2&&$e['schema']===2&&$e['binding']['refund_contract_version']===2&&$row['refund_id']===$refund
        &&$row['root_order_id']===$root&&$row['amount']==='40.30'&&$e['binding']['confirmed_before']==='0.00'
        &&$e['binding']['requested_amount']==='40.30'&&count($e['observations'])===($final?3:2)&&count($e['applications'])===($final?3:2),
        'same_v2_intent_and_complete_actual_progress_facts');
    if($previous['refund_attempts']!==[]){
        $old=\app\services\marketplace\payment\MarketplaceRefundEvidence::row($previous['refund_attempts'][0]);
        foreach(['observations','applications'] as $name)
            frp_check(\app\services\marketplace\payment\MarketplaceRefundEvidence::canonical(array_slice($e[$name],0,count($old['evidence'][$name])))
                ===\app\services\marketplace\payment\MarketplaceRefundEvidence::canonical($old['evidence'][$name]),'original_evidence_prefix_held');
        foreach(array_keys($old)as$field)if(!in_array($field,['evidence','updated_at','applied_refund_no'],true))
            frp_check($row[$field]===$old[$field],'original_intent_identity_and_descriptor_held');
    }
    $finance=array_slice($now['refund_actions'],count($before['refund_actions']));frp_check(count($finance)===count($e['applications']),'one_real_finance_action_per_application');
    foreach($e['applications']as$i=>$a){
        $native=$finance[$i];$body=json_decode($native['request_payload'],true,64,JSON_THROW_ON_ERROR)['public_body'];
        frp_check((string)$native['id']===$a['action_id']&&$native['action']==='finance_observe'&&$native['actor_type']==='payment'
            &&$native['actor_id']===null&&(int)$native['refund_id']===$refund&&(int)$native['committed_at']===$a['applied_at']
            &&$body['source_ref']==='test-refund:'.$row['id'].':'.$a['fact']&&$body['confirmed_amount']===$a['confirmed_amount']
            &&$body['observed_state']===$a['finance_state'],'each_application_joined_to_actual_committed_Writer_action');
    }
    frp_check($e['applications'][0]['confirmed_amount']==='0.00'&&$e['applications'][0]['finance_state']==='PROCESSING'
        &&$e['applications'][1]['confirmed_amount']==='20.15'&&$e['applications'][1]['finance_state']==='PARTIAL','actual_zero_then_partial_facts');
    if($final)frp_check($e['applications'][2]['confirmed_amount']==='40.30'&&$e['applications'][2]['finance_state']==='SUCCEEDED'
        &&$e['application']['fact']===$e['applications'][2]['fact'],'actual_final_application_pointer');
    else frp_check($e['application']===null&&$row['applied_refund_no']===null,'partial_is_not_final_application');
    return $now;
}
function rpp_reads(array $tokens,int $root,int $refund,string $number,string $confirmed,bool $final):array
{
    $out=[];
    foreach(['customer'=>'/api/order/refund/detail/'.rawurlencode($number),'merchant'=>'/merchantapi/aftersales/'.$refund,
        'admin'=>'/adminapi/marketplace/aftersales/'.$refund]as$role=>$path){
        $v=frp_http($role,'GET',$path,null,$tokens[$role]);frp_view($v,$root,$refund,$number,'money_only','40.30',$final);
        frp_check($v['finance_state']===($final?'SUCCEEDED':'PARTIAL')&&$v['confirmed_amount']===$confirmed
            &&$v['held_amount']===bcsub('40.30',$confirmed,2)&&$v['business_state']===($final?'COMPLETED':'APPROVED'),'fresh_three_role_saved_progress_read');
        $out[$role]=array_intersect_key($v,array_flip(['id','root_order_id','business_state','physical_state','finance_state','requested_amount','confirmed_amount','held_amount','is_test_payment']));
    }
    $options=frp_http('merchant','GET','/merchantapi/aftersales/'.$refund.'/test-refund-options',null,$tokens['merchant']);
    frp_check($options['available']===false&&$options['state']===($final?'applied':'pending')
        &&$options['reason']===($final?'ALREADY_APPLIED':'PENDING_CONFIRMATION')&&$options['amount']==='40.30'
        &&$options['confirmed_amount']===$confirmed&&$options['held_amount']===bcsub('40.30',$confirmed,2),'new_HTTP_options_saved_facts_only');
    return $out;
}
function rpp_continue(\think\App $app,\app\Request $request,object $rule,\app\merchantapi\controller\Aftersale $controller,
    array $fixed,int $refund,string $wire):array
{
    rpp_factory($app,$fixed);rpp_route($app,$request,$rule,$refund,$wire);
    // The same read-only release gate used by AppService::boot, never an offline/standalone substitute.
    \crmeb\services\marketplace\SchemaVersionRuntime::assertApp($app);
    $response=$app->make(\app\merchantapi\middleware\MerchantAuthTokenMiddleware::class)->handle($request,
        static function($actual)use($app,$request,$rule,$controller,$refund,$wire){
            frp_check($actual===$request,'actual_original_Request_entered_authentication');rpp_route($app,$actual,$rule,$refund,$wire);
            return $app->make(\app\merchantapi\middleware\MerchantPermissionMiddleware::class)->handle($actual,
                static function($authorized)use($request,$controller){
                    frp_check($authorized===$request,'actual_Request_after_permission_and_quota');return $controller->testRefund($authorized);
                });
        });
    rpp_factory($app,$fixed);rpp_route($app,$request,$rule,$refund,$wire);return rpp_response($response);
}

try{
    frp_check(PHP_SAPI==='cli'&&$argc===4&&extension_loaded('curl')&&extension_loaded('bcmath'),'fixed_CLI_actor_inputs');
    foreach([$argv[1],$argv[2]]as$input)frp_check(is_string($input)&&str_starts_with($input,'/run/refund-progress/')&&is_file($input)&&!is_link($input)&&str_starts_with((string)realpath($input),'/run/refund-progress/'),'only_mounted_parameter_files');
    $plan=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
    frp_check(is_array($plan)&&array_keys($plan)===['project','run_id','pause_after_partial']&&is_bool($plan['pause_after_partial']),'public_plan_exact_fields');
    frp_check(!is_file('/run/preview-bootstrap/config.json')&&!is_link('/run/preview-bootstrap/config.json'),'no_bootstrap_config_before_any_private_config_read');
    preview_assert_isolated();$config=preview_config();
    frp_check(!is_file('/run/preview-bootstrap/config.json')&&!is_link('/run/preview-bootstrap/config.json')
        &&!array_key_exists('migrator',$config['database'])&&$config['project']===$plan['project']&&$config['run_id']===$plan['run_id']
        &&($config['full_runtime']??null)===true&&($config['image_runtime']??null)===false&&($config['legacy_review_fixture']??null)===false,
        'new_synthetic_preview_app_DML_configuration_only_no_bootstrap_credentials');
    // Public source identity only; private parameter files are never hashed.
    foreach([
        'crmeb/crmeb/services/pay/marketplace/TestPaymentDriver.php'=>'e59668132e1c30f7302f8249af9717a2d85caf640d1350e340dce99a293ea402',
        'crmeb/app/services/marketplace/payment/MarketplaceRefundEvidence.php'=>'faf970127608b00ab88fca1f5f7627c127066a3c6fbe9482f4a21fe6e0ae4d63',
        'crmeb/app/services/marketplace/payment/MarketplaceRefundServices.php'=>'d1d23816e0a4325c7eb64b43d2a69b94f90d6d595d6b35426bab639c02e2ec66',
        'crmeb/app/dao/marketplace/payment/MarketplaceRefundDao.php'=>'2945bd9323f17745dcfc7f58e066d335a0573838f6539cb787ddbd897b7ebf01',
        'help/preview/common.php'=>'6c8789267f4c485c5721a880d99aafebdf35cb8fc90d6980f1e3e5c14ae91a31',
        'crmeb/app/Request.php'=>'dddc134978b543d61aa6ef2f5f08493ab9791c4a33a191345c7af088a5d017bf',
        'crmeb/app/merchantapi/route/aftersale.php'=>'8465586e09a5fec9391935afba5d1f612092d7121046ff9f11828d8a5aabbced',
        'crmeb/app/merchantapi/controller/Aftersale.php'=>'6f29962eb4e4788efeea4075fece330a3f2ebd7da715ee5540e4eac26ca86a8f',
        'crmeb/app/merchantapi/middleware/MerchantAuthTokenMiddleware.php'=>'627e8b34af93746145dd8ad492700a763b9ed881722fdec63c745dda7bb7e7e2',
        'crmeb/app/merchantapi/middleware/MerchantPermissionMiddleware.php'=>'01a7b846ee9b99b211a814f66cc812ab132254e4e77bc91f269477d710af74c1',
        'crmeb/app/merchantapi/middleware/MerchantRequestQuotaMiddleware.php'=>'217ec0079d007bce2e268c03bec1fb8a2ce48ee03290fe7fd7fb32247fdae9bc',
        'crmeb/crmeb/services/marketplace/SchemaVersionRuntime.php'=>'073d4661495d5b4a57ad129152f53e86fd061ce43d7b03eb4b6cc26543a0a7b5',
        'crmeb/vendor/topthink/framework/src/think/Http.php'=>'9cb315138bd8ef6991b42f78209e3d8f762082002182b932bc60ca3642c5f98a',
        'crmeb/vendor/topthink/think-multi-app/src/MultiApp.php'=>'bebd8776c2c606014d9c37c05ebb76b7e6451af2c8fa39f55369aab48197a61e',
        'crmeb/vendor/topthink/framework/src/think/Container.php'=>'afc8bcd902bee8ad5868638c475d9f131b1b8e1bb61b7301d599779ee5de8acb',
        'crmeb/vendor/topthink/framework/src/think/route/dispatch/Controller.php'=>'6915b54a9fa2610413a9da8957e3b07d1737176ff62531831204c59ebe8a14aa',
    ]as$relative=>$hash)frp_check(hash_file('sha256','/workspace/'.$relative)===$hash,'same_reviewed_candidate_and_original_auth_kernel_sources');
    define('FRP_RUN',$plan['run_id']);$kind='money_only';$mode='partial_progress';
    $file=$argv[3];frp_check(is_string($file)&&dirname($file)==='/out'&&basename($file)==='actor-result.json'&&!file_exists($file)&&!is_link($file),'new_own_actor_receipt');
    $frpFile=fopen($file,'x+b');frp_check(is_resource($frpFile)&&flock($frpFile,LOCK_EX|LOCK_NB)&&chmod($file,0600),'new_private_receipt');
    $frpState=['schema'=>1,'scope'=>'NORMAL_SAME_APP_REFUND_PROGRESS_INTERNAL_CONTINUATION_ONLY','project'=>$plan['project'],'run_id'=>$plan['run_id'],
        'stage'=>'preparation','operations'=>[],'full_acceptance'=>false,'DOM_MiniProgram_real_funds'=>'NOT RUN','source_scope'=>'FOUR_CANDIDATE_PRODUCT_FILES_AND_EXISTING_TRACKED_APP',
        'credential_inputs'=>'PATH_ONLY_CONTENT_RAM_NOT_SAVED_OR_HASHED'];frp_save();
    $initialized=json_decode(file_get_contents('/run/preview-app/initialized.json'),true,64,JSON_THROW_ON_ERROR);
    frp_check($initialized['project']===$plan['project']&&$initialized['run_id']===$plan['run_id']&&($initialized['result']['synthetic_only']??null)===true,'actual_same_synthetic_seed');
    $fixture=$initialized['result'];$product=$fixture['products'][0]??null;frp_check(is_array($product),'actual_seed_product');
    $p=['product_id'=>frp_id($product['product_id']??null),'sku_id'=>frp_id($product['sku_id']??null),'sku_unique'=>$product['sku_unique']??null,
        'merchant_id'=>frp_id($fixture['merchant_id']??null),'customer_id'=>frp_id($fixture['customer_id']??null),'address_id'=>frp_id($fixture['address_id']??null)];
    frp_check(is_string($p['sku_unique'])&&preg_match('/\A[a-f0-9]{8}\z/D',$p['sku_unique'])===1,'actual_seed_SKU');
    $db=preview_pdo();frp_check($db->query('SELECT DATABASE()')->fetchColumn()==='mall_preview','same_preview_database');
    $credentials=json_decode(file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR);
    frp_check(is_array($credentials)&&array_keys($credentials)===['admin','merchant','customer'],'only_three_existing_synthetic_credentials');
    $tokens=[];foreach(['admin','merchant','customer']as$role)$tokens[$role]=frp_login($role,$credentials[$role]);unset($credentials);
    $me=frp_http('merchant','GET','/merchantapi/me',null,$tokens['merchant']);
    frp_check(frp_id($me['merchant']['mer_id']??null)===$p['merchant_id']&&in_array('aftersale.test_refund',$me['role']['permissions']??[],true),
        'already_authorized_merchant_role_no_role_or_permission_mutation');
    $actualUser=frp_http('customer','GET','/api/user',null,$tokens['customer']);
    frp_check(frp_id($actualUser['uid']??null)===$p['customer_id'],'actual_login_customer_UID_matches_synthetic_fixture');unset($actualUser);
    $stockStart=frp_stock($db,$p);frp_check(count($stockStart['product'])===1&&count($stockStart['sku'])===1,'real_stock_rows');
    $detail=frp_http('customer','GET','/api/product/detail/'.$p['product_id'],null,$tokens['customer']);
    $attrs=frp_http('customer','GET','/api/v2/get_attr/'.$p['product_id'].'/0',null,$tokens['customer']);$sku=null;
    foreach($attrs['productValue']??[]as$row)if(($row['unique']??null)===$p['sku_unique']){frp_check($sku===null,'unique_sku');$sku=$row;}
    frp_check(is_array($sku)&&frp_id($sku['id']??null)===$p['sku_id']&&frp_id($detail['storeInfo']['mer_id']??null)===$p['merchant_id'],'real_product_sku_owner');
    $unit=frp_money($sku['price']??null);$quantity=2;$goods=bcmul($unit,(string)$quantity,2);$prefix='refund-partial-progress:'.$kind.':'.FRP_RUN.($mode==='prepare_ui'?':UI':'');
    frp_check($unit==='20.15'&&$goods==='40.30','original_seed_price_no_price_mutation');
    $cart=frp_http('customer','GET','/api/cart/list?page=1&limit=20',null,$tokens['customer']);frp_check(($cart['valid']??null)===[]&&($cart['invalid']??null)===[],'new_case_empty_cart');
    $added=frp_http('customer','POST','/api/cart/add',['productId'=>$p['product_id'],'cartNum'=>$quantity,'uniqueId'=>$p['sku_unique'],'new'=>0,'is_new'=>0],$tokens['customer']);
    $cartId=(string)frp_id($added['cartId']??null);
    $quote=frp_http('customer','POST','/api/order/confirm',['cartId'=>$cartId,'mer_id'=>$p['merchant_id'],'addressId'=>$p['address_id'],'new'=>0,'shipping_type'=>1,'is_gift'=>0],$tokens['customer']);
    frp_check(($quote['marketplace_confirmation_version']??null)===1&&($quote['marketplace_create_enabled']??null)===true
        &&($quote['source_kind']??null)==='persistent'&&frp_money($quote['priceGroup']['totalPrice']??null)===$goods,'normal_confirmation');
    $key=$quote['orderKey']??null;frp_check(is_string($key)&&preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D',$key)===1,'actual_confirmation_key');
    $computed=frp_http('customer','POST','/api/order/computed/'.$key,['addressId'=>$p['address_id'],'shipping_type'=>1,'payType'=>'','mer_id'=>$p['merchant_id'],'couponId'=>0,'useIntegral'=>0],$tokens['customer']);
    frp_check(($computed['status']??null)==='NONE','computed_no_real_payment');$amount=frp_money($computed['result']['pay_price']??null);
    frp_check($amount===$goods&&frp_money($computed['result']['pay_postage']??null)==='0.00','saved_seed_free_delivery_amount');
    $created=frp_http('customer','POST','/api/order/create/'.$key,['cartId'=>$cartId,'mer_id'=>$p['merchant_id'],'new'=>0,'idem_key'=>$prefix.':create',
        'addressId'=>$p['address_id'],'invoice_id'=>0,'payType'=>'','shipping_type'=>1,'is_gift'=>0,'couponId'=>0,'useIntegral'=>false,'custom_form'=>[]],$tokens['customer']);
    frp_check(($created['status']??null)==='SUCCESS'&&($created['result']['key']??null)===$key,'normal_created_result');
    $orderNo=$created['result']['orderId']??null;frp_check(is_string($orderNo)&&preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D',$orderNo)===1,'actual_order_number');
    $list=frp_http('merchant','GET','/merchantapi/orders?page=1&limit=100',null,$tokens['merchant']);$matches=[];
    foreach($list['list']??[]as$row)if(($row['order_id']??null)===$orderNo)$matches[]=$row;frp_check(count($matches)===1,'one_created_root');$root=frp_id($matches[0]['id']??null);
    $order=frp_http('merchant','GET','/merchantapi/orders/'.$root,null,$tokens['merchant']);
    frp_check(($order['paid']??null)===0&&($order['total_num']??null)===$quantity&&count($order['items']??[])===1&&frp_money($order['pay_price']??null)===$amount,'normal_root_quantities_amount');
    $item=frp_id($order['items'][0]['id']??null);frp_check(($order['items'][0]['quantity']??null)===$quantity,'actual_root_item_quantity');
    $payOptions=frp_http('customer','GET','/api/order/'.$orderNo.'/test-payment',null,$tokens['customer']);frp_check(($payOptions['available']??null)===true&&($payOptions['real_payment_available']??null)===false,'testing_payment_only');
    $paid=frp_http('customer','POST','/api/order/'.$orderNo.'/test-payment',['payment_idem'=>$prefix.':pay'],$tokens['customer']);frp_check(($paid['state']??null)==='applied','original_test_payment_applied');
    $afQuote=frp_http('customer','GET','/api/order/refund/cart_info/'.$root.'?contract=2&fulfillment_page=1&fulfillment_limit=20',null,$tokens['customer']);
    frp_check(($afQuote['schema']??null)===2&&($afQuote['allowed_actions']['apply']??null)===true
        &&frp_money($afQuote['capacity']['available']??null)===$amount,'saved_full_refund_capacity');
        frp_check(count($afQuote['root_items']??[])===1,'one_actual_refund_root_item');$r=$afQuote['root_items'][0];
        frp_check(frp_id($r['item']['id']??null)===$item&&($r['item']['quantity']??null)===$quantity,'quote_root_item_join');
        if($kind==='cancel_unshipped')frp_check(($r['available_cancel_quantity']??null)===$quantity,'entire_unshipped_selection');
        $selection=['mode'=>'root_quantities','items'=>[['item_id'=>$item,'quantity'=>$quantity]]];
    $applied=frp_http('customer','POST','/api/order/refund/apply/'.$root,['schema'=>2,'idem_key'=>$prefix.':apply','operation_kind'=>$kind,
        'amount'=>$amount,'reason'=>'本机合成测试退款联调','images'=>[],'selection'=>$selection],$tokens['customer']);
    $v=$applied['refund']??null;frp_check(is_array($v),'actual_application_result');$refund=frp_id($v['id']??null);$refundNo=$v['refund_no']??null;frp_check(is_string($refundNo)&&$refundNo!=='','actual_refund_number');
    $approval=['schema'=>2,'idem_key'=>$prefix.':approve'];
    if($kind==='return_goods')$approval['return_destination']=['recipient_name'=>'合成收件人','phone'=>'13800000000','address'=>'本机隔离预览合成回寄地址'];
    frp_http('merchant','POST','/merchantapi/aftersales/'.$refund.'/approve',$approval,$tokens['merchant']);
    $v=frp_http('merchant','GET','/merchantapi/aftersales/'.$refund,null,$tokens['merchant']);frp_view($v,$root,$refund,$refundNo,$kind,$amount,false);
    frp_check(($v['business_state']??null)==='APPROVED'&&($v['confirmed_amount']??null)==='0.00'&&($v['held_amount']??null)===$amount,'approved_full_unconfirmed_amount');
    if($kind==='return_goods')frp_check(($v['physical_state']??null)==='RETURN_ACCEPTED','entire_return_physically_accepted');
    $executor=$kind==='cancel_unshipped'?'admin':'merchant';$refundPath=($executor==='admin'?'/adminapi/marketplace':'/merchantapi').'/aftersales/'.$refund;
    $options=frp_http($executor,'GET',$refundPath.'/test-refund-options',null,$tokens[$executor]);
    frp_check(($options['schema']??null)===1&&($options['available']??null)===true&&($options['state']??null)==='not_started'
        &&($options['reason']??null)==='AVAILABLE'&&($options['amount']??null)===$amount&&($options['confirmed_amount']??null)==='0.00'
        &&($options['held_amount']??null)===$amount&&($options['real_refund_enabled']??null)===false,'real_test_refund_options');
    $before=frp_facts($db,$root,$refund,$p);frp_check($before['refund_attempts']===[],'one_new_refund_intent_only');
    frp_check(count($before['payment_registry'])===1&&count($before['payment_attempts'])===1,'one_original_payment_winner');
    $originalPayment=$before['payment_attempts'][0];$nativeRoot=null;
    foreach($before['orders']as$row)if(frp_id($row['id'])===$root)$nativeRoot=$row;
    frp_check(is_array($nativeRoot)&&(int)$nativeRoot['paid']===1&&$nativeRoot['pay_type']==='marketplace_test'
        &&frp_money($nativeRoot['pay_price'])===$amount&&(int)$nativeRoot['total_num']===$quantity
        &&(string)$originalPayment['id']===(string)$before['payment_registry'][0]['winner_attempt_id']
        &&$originalPayment['applied_trade_no']===$nativeRoot['trade_no'],'saved_original_payment_amount_and_trade_number');
    $refundKey=$prefix.':refund';

    frp_check($amount==='40.30'&&$executor==='merchant','one_fresh_full_40_30_money_only_application');
    // A real initialized App; preview_app loads official configuration and AppService's schema gate.
    frp_check(!file_exists('/workspace/crmeb/.env')&&!is_link('/workspace/crmeb/.env'),'official_App_no_unbound_env_file');
    $frpStep='same_App_boot';$app=preview_app(false);$rppApp=$app;
    frp_check(app()===$app&&$app->initialized()&&$app->config->get('marketplace.enabled')===true
        &&$app->config->get('marketplace.payment.test_enabled')===true&&getenv('APP_ENV')==='testing','actual_enabled_App_configuration');
    $connection=\think\facade\Db::connect();$connection->query('SELECT 1',[],true);$default=$connection->getPdo();
    $driver=$app->make(\crmeb\services\pay\marketplace\PaymentAdapter::class);$service=$app->make(\app\services\marketplace\payment\MarketplaceRefundServices::class);
    frp_check($driver instanceof \crmeb\services\pay\marketplace\TestPaymentDriver&&$default instanceof PDO&&$default!==$db,'actual_same_driver_and_distinct_observer');
    $identity=$default->query('SELECT DATABASE() AS db,CURRENT_USER() AS account')->fetch(PDO::FETCH_ASSOC);
    frp_check(is_array($identity)&&$identity['db']==='mall_preview'&&is_string($config['database']['app']['username']??null)
        &&str_starts_with($identity['account'],$config['database']['app']['username'].'@')&&$connection->getConfig('hostname')==='preview-mysql'
        &&$default->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN)===[$plan['run_id']],
        'actual_App_default_DML_identity_and_same_new_preview_sentinel');unset($identity);
    $fixed=['driver'=>$driver,'service'=>$service,'connection'=>$connection,'pdo'=>$default];rpp_factory($app,$fixed);
    $driver->planTestRefundProgress($refund,$refundKey,['20.15','40.30']);rpp_factory($app,$fixed);
    $controller=null;$controllerResolutions=0;
    $app->resolving(\app\merchantapi\controller\Aftersale::class,static function($resolved,$container)use($app,&$controller,&$controllerResolutions){
        frp_check($container===$app&&$resolved instanceof \app\merchantapi\controller\Aftersale&&$controller===null,'observe_original_framework_controller_without_replacing_it');
        $controller=$resolved;$controllerResolutions++;
    });
    $http=$app->http;frp_check($http->getName()===''&&$http->getPath()===''&&!$http->isBind()
        &&!$app->exists(\think\app\MultiApp::class),'first_official_MultiApp_dispatch_only');
    $wire=json_encode(['refund_idem'=>$refundKey],JSON_THROW_ON_ERROR);$path='/merchantapi/aftersales/'.$refund.'/test-refund';
    $server=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>$path,'PATH_INFO'=>$path,'QUERY_STRING'=>'',
        'SCRIPT_FILENAME'=>'/workspace/crmeb/public/index.php','SCRIPT_NAME'=>'/index.php','PHP_SELF'=>'/index.php',
        'SERVER_NAME'=>'preview-web','HTTP_HOST'=>'preview-web:8080','SERVER_PORT'=>'8080','REQUEST_SCHEME'=>'http','SERVER_PROTOCOL'=>'HTTP/1.1',
        'CONTENT_TYPE'=>'application/json','CONTENT_LENGTH'=>(string)strlen($wire),'REMOTE_ADDR'=>gethostbyname(gethostname())];
    frp_check(is_file($server['SCRIPT_FILENAME'])&&!is_link($server['SCRIPT_FILENAME'])
        &&filter_var($server['REMOTE_ADDR'],FILTER_VALIDATE_IP)!==false,'actual_public_entry_and_container_address');
    $_SERVER=array_replace($_SERVER,$server);$request=$app->make('request',[],true);$rppRequest=$request;
    frp_check($request instanceof \app\Request,'fresh_official_Request');
    $request->withServer($server)->withEnv($app->env)->withHeader(['host'=>'preview-web:8080','content-type'=>'application/json',
        'content-length'=>(string)strlen($wire),'accept'=>'application/json','cb-lang'=>'zh-CN','authorization'=>'Bearer '.$tokens['merchant']])
        ->withGet([])->withCookie([])->withFiles([])->withInput($wire);
    frp_check($request->method(true)==='POST'&&$request->post(false)===['refund_idem'=>$refundKey],'real_raw_POST_body_only_original_idempotency_key');
    $frpStep='first_real_kernel';$response=$http->run($request);$rppResponse=$response;$dto=rpp_response($response);
    frp_check($controllerResolutions===1&&$controller instanceof \app\merchantapi\controller\Aftersale,'actual_original_controller_observed');
    $rule=$request->rule();frp_check(is_object($rule),'actual_matched_route_rule');rpp_route($app,$request,$rule,$refund,$wire);rpp_factory($app,$fixed);
    $partial=rpp_dto($dto,$refund,$refundKey,'20.15',false);$partialFacts=rpp_preserved($db,$before,$before,$root,$refund,$p,'20.15',false);
    $partialReads=rpp_reads($tokens,$root,$refund,$refundNo,'20.15',false);
    frp_check(frp_facts($db,$root,$refund,$p)===$partialFacts,'fresh_three_role_reads_target_rows_unchanged');
    $same=rpp_continue($app,$request,$rule,$controller,$fixed,$refund,$wire);
    frp_check($same===$dto&&frp_facts($db,$root,$refund,$p)===$partialFacts,'same_known_descriptor_query_replay_no_new_fact_action_or_inventory');
    $frpState['stage']='PARTIAL_COMMITTED_AND_NEW_THREE_ROLE_HTTP_VERIFIED';
    $frpState['partial']=['result'=>$partial,'three_role_HTTP'=>$partialReads,'target_snapshot'=>frp_digest($partialFacts),'same_fact_internal_query_unchanged'=>true];frp_save();
    echo json_encode(['status'=>'PARTIAL_READY','root_id'=>$root,'refund_id'=>$refund,'confirmed_amount'=>'20.15','held_amount'=>'20.15',
        'new_three_role_HTTP'=>true,'full_acceptance'=>false],JSON_THROW_ON_ERROR)."\n";flush();
    if($plan['pause_after_partial']){
        // Optional bounded normal UI observation barrier. It carries no identity, credentials or business inputs.
        $deadline=time()+300;$line=null;
        while(time()<$deadline){$read=[STDIN];$write=$except=null;$n=stream_select($read,$write,$except,1);
            frp_check($n!==false,'normal_partial_observation_barrier');if($n>0){$line=fgets(STDIN);break;}}
        frp_check($line==="continue\n",'explicit_normal_continuation_within_bounded_window');
    }
    $frpStep='post_pause_full_observation_before_driver_advance';
    // All observations precede any advance. No configuration, sentinel, row or driver state is reconstructed.
    preview_assert_isolated();
    frp_check(preview_config()===$config&&json_decode(file_get_contents('/run/preview-app/initialized.json'),true,64,JSON_THROW_ON_ERROR)===$initialized,
        'post_pause_original_configuration_and_initialization_marker_held');
    rpp_factory($app,$fixed);rpp_route($app,$request,$rule,$refund,$wire);
    \crmeb\services\marketplace\SchemaVersionRuntime::assertApp($app);
    $postPauseFacts=frp_facts($db,$root,$refund,$p);$postPauseDigest=frp_digest($postPauseFacts);
    $prePauseDigest=frp_digest($partialFacts);$orderNotificationChange=null;$inversePostPause=$postPauseFacts;
    if($postPauseFacts['orders']!==$partialFacts['orders']){
        frp_check($plan['pause_after_partial']===true&&count($partialFacts['orders'])===1&&count($postPauseFacts['orders'])===1
            &&$partialFacts['orders'][0]['id']===$root&&$postPauseFacts['orders'][0]['id']===$root
            &&$partialFacts['orders'][0]['is_remind']===0&&$postPauseFacts['orders'][0]['is_remind']===1,
            'only_this_new_root_typed_notification_zero_to_one_in_explicit_pause');
        // The original postcheck's inverse exists only in RAM. It is never written or described as the observed row.
        $inverse=$postPauseFacts['orders'];$inverse[0]['is_remind']=0;
        frp_check($inverse===$partialFacts['orders']&&$inverse===$before['orders']
            &&frp_digest(['orders'=>$inverse])['orders']===$prePauseDigest['orders']
            &&frp_digest(['orders'=>$inverse])['orders']===frp_digest(['orders'=>$before['orders']])['orders'],
            'exact_single_field_inverse_all_original_order_values_types_rows_and_full_digest');
        $inversePostPause['orders']=$inverse;
        $orderNotificationChange=['field'=>'is_remind','before'=>0,'actual_after'=>1,'root_id'=>$root,
            'all_other_original_order_fields_types_rows_held'=>true,'actual_native_digest'=>$postPauseDigest['orders'],
            'original_before_digest'=>$prePauseDigest['orders'],'database_writes_performed'=>false,
            'causal_request_capture'=>'NOT RUN_EXISTING_SOURCE_PATTERN_ONLY'];
        unset($inverse);
    }
    frp_check($inversePostPause===$partialFacts&&frp_digest($inversePostPause)===$prePauseDigest,
        'post_pause_every_other_financial_payment_inventory_and_target_domain_exactly_held');
    $frpState['post_pause_observation']=['scope'=>'READ_ONLY_OBSERVATION_BEFORE_ANY_ORIGINAL_DRIVER_ADVANCE',
        'pause_after_partial'=>$plan['pause_after_partial'],'order_notification_change'=>$orderNotificationChange,
        'original_partial_target_digest'=>$prePauseDigest,'actual_post_pause_target_digest'=>$postPauseDigest,
        'all_other_target_domains_exact'=>true,'original_configuration_initialization_and_sentinels_held'=>true,
        'observation_database_writes_performed'=>false,'jnotice_request_captured'=>false];
    // Only now carry the actual observed orders into the next strict RAM baselines. The original partial receipt stays intact.
    if($orderNotificationChange!==null){$before['orders']=$postPauseFacts['orders'];$partialFacts['orders']=$postPauseFacts['orders'];}
    frp_save();unset($inversePostPause,$postPauseFacts,$postPauseDigest,$prePauseDigest,$orderNotificationChange);
    $intent=$app->make(\app\dao\marketplace\payment\MarketplaceRefundDao::class)->find($default,$refund,false);
    frp_check(is_array($intent)&&$intent['id']===$dto['attempt_id'],'original_saved_intent');
    $descriptor=\app\services\marketplace\payment\MarketplaceRefundEvidence::descriptor($intent);
    rpp_factory($app,$fixed);$internal=$driver->advanceTestRefund($descriptor);
    frp_check($internal['state']==='succeeded'&&$internal['confirmed_amount']==='40.30'
        &&frp_facts($db,$root,$refund,$p)===$partialFacts,'internal_advance_original_descriptor_without_database_or_inventory_write');
    $frpStep='same_App_authenticated_internal_continuation';$finalDto=rpp_continue($app,$request,$rule,$controller,$fixed,$refund,$wire);
    $final=rpp_dto($finalDto,$refund,$refundKey,'40.30',true);frp_check($final['attempt_id']===$partial['attempt_id'],'same_attempt_through_completion');
    $finalFacts=rpp_preserved($db,$before,$partialFacts,$root,$refund,$p,'40.30',true);$finalReads=rpp_reads($tokens,$root,$refund,$refundNo,'40.30',true);
    frp_check(frp_facts($db,$root,$refund,$p)===$finalFacts,'new_final_three_role_HTTP_rows_held');
    $same=rpp_continue($app,$request,$rule,$controller,$fixed,$refund,$wire);
    frp_check($same===$finalDto&&frp_facts($db,$root,$refund,$p)===$finalFacts,'final_internal_saved_fact_replay_unchanged');
    $replay=frp_http('merchant','POST',$refundPath.'/test-refund',['refund_idem'=>$refundKey],$tokens['merchant']);
    frp_check($replay===$finalDto&&frp_facts($db,$root,$refund,$p)===$finalFacts,'final_new_HTTP_saved_fact_replay_unchanged');
    frp_check($controllerResolutions===1,'only_first_kernel_controller_no_new_controller_or_second_MultiApp_dispatch');
    $frpState['stage']='COMPLETE_NORMAL_PROGRESS_INTERNAL_CONTINUATION_ONLY';
    $frpState['final']=['result'=>$final,'three_role_HTTP'=>$finalReads,'target_snapshot'=>frp_digest($finalFacts),
        'same_attempt_descriptor_original_v1_payment_and_inventory_held'=>true,'first_real_kernel_dispatch'=>1,
        'later_calls'=>'ORIGINAL_AUTH_PERMISSION_QUOTA_CONTROLLER_INTERNAL_CONTINUATION_NOT_FULL_KERNEL',
        'new_HTTP_final_replay_unchanged'=>true,'old_PARTIAL_history_cross_process_restore_real_funds'=>'NOT RUN'];frp_save();
    $rppSuccess=['status'=>'PASS_NORMAL_SAME_APP_REFUND_PARTIAL_PROGRESS_ONLY','root_id'=>$root,'refund_id'=>$refund,'attempt_id'=>$final['attempt_id'],
        'phases'=>[['confirmed_amount'=>'20.15','held_amount'=>'20.15'],['confirmed_amount'=>'40.30','held_amount'=>'0.00']],
        'new_three_role_HTTP_each_phase'=>true,'full_acceptance'=>false];
}catch(Throwable $error){
    if(is_array($frpState)){$frpState['stage']='STOPPED_NO_AUTOMATIC_RETRY';$frpState['failure']=['step'=>$frpStep,'code'=>$frpFailure??'UNCLASSIFIED_NATIVE_FAILURE','class'=>get_class($error)];try{frp_save();}catch(Throwable $ignored){}}
    echo json_encode(['status'=>'INCOMPLETE_PRESERVE_COMMITTED_FACTS_NO_RETRY','step'=>$frpStep,'code'=>$frpFailure??'UNCLASSIFIED_NATIVE_FAILURE','class'=>get_class($error),'full_acceptance'=>false],JSON_THROW_ON_ERROR)."\n";
    $GLOBALS['rppExit']=1;
}finally{
    if($rppApp instanceof \think\App&&$rppResponse instanceof \think\Response){try{$rppApp->http->end($rppResponse);}catch(Throwable $error){$GLOBALS['rppExit']=1;if(is_array($frpState)){$frpState['stage']='INCOMPLETE_NORMAL_HTTP_END';$frpState['failure']=['code'=>'NORMAL_HTTP_END_FAILED','class'=>get_class($error)];try{frp_save();}catch(Throwable $ignored){}}echo json_encode(['status'=>'INCOMPLETE_NORMAL_HTTP_END','full_acceptance'=>false],JSON_THROW_ON_ERROR)."\n";}}
    if($rppRequest instanceof \app\Request)$rppRequest->withHeader([]);$_SERVER=$rppServerBefore;
    unset($credentials,$config,$tokens,$descriptor,$internal,$intent,$initialized);if(is_resource($frpFile))fclose($frpFile);
}
if(($GLOBALS['rppExit']??0)===0&&isset($rppSuccess))echo json_encode($rppSuccess,JSON_THROW_ON_ERROR)."\n";
exit($GLOBALS['rppExit']??0);
