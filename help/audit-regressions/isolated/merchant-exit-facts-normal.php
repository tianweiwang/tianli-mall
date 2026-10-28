<?php
declare(strict_types=1);

/** Pure normal fixtures only: no App bootstrap, DB, Redis, provider, worker or environment write. */
require_once __DIR__.'/../../../crmeb/vendor/autoload.php';

use app\services\marketplace\MerchantExitFacts;
use app\services\marketplace\order\MarketplaceOrderInput;
use app\dao\marketplace\order\MarketplaceOrderDao;

function exitNormalExpect(bool $value,string $name): void
{ if(!$value)throw new RuntimeException($name); }

$merchant=['mer_id'=>7,'review_status'=>1,'accept_orders'=>0,'security_locked'=>0,'closed_at'=>null,'fulfillment_enabled'=>1];
$empty=array_fill_keys(MerchantExitFacts::COLLECTIONS,[]);
$result=MerchantExitFacts::assess($merchant,1700000100,$empty);
exitNormalExpect($result===['schema'=>1,'scope'=>'MERCHANT_EXIT_PRECHECK','mer_id'=>7,'closed_at'=>null,'checked_at'=>1700000100,
    'can_close'=>true,'responsibilities'=>array_map(fn(string $key):array=>['key'=>$key,'state'=>'CLEAR','count'=>0,'reasons'=>[]],
        ['fulfillment','payment','refund','settlement'])],'EMPTY_MERCHANT_EXACT_CONTRACT');

$root=['id'=>11,'uid'=>3,'mer_id'=>7,'order_id'=>'exit-normal-order','unique'=>'exit-normal-confirm','marketplace_trade_version'=>1,
    'cart_id'=>'["51"]','total_num'=>1,'total_price'=>'10.00','total_postage'=>'0.00','pay_price'=>'10.00','pay_postage'=>'0.00',
    'cost'=>'5.00','paid'=>0,'is_cancel'=>1,'is_del'=>0,'is_system_del'=>0,'pay_uid'=>3,'add_time'=>1700000000,
    'merchant_name_snapshot'=>'normal','merchant_after_sale_contact_snapshot'=>'','pay_type'=>'','pay_time'=>0,'trade_no'=>'',
    'pid'=>0,'status'=>0,'refund_status'=>0,'shipping_type'=>1,'is_gift'=>0,'delivery_type'=>'','delivery_id'=>'','delivery_name'=>''];
$ledger=['uid'=>3,'mer_id'=>7,'idem_key'=>'exit-normal-create','request_digest'=>hash('sha256','normal',true),
    'confirmation_ref'=>hash('sha256',$root['unique'],true),'source_kind'=>'persistent','cart_refs'=>'["51"]','state'=>1,
    'order_id'=>11,'post_commit_done'=>1,'cancel_at'=>1700000050,'add_time'=>1700000000];
$registry=['order_id'=>11,'uid'=>3,'mer_id'=>7,'payment_contract_version'=>1,'born_at'=>1700000000,'attempt_generation'=>'0',
    'winner_attempt_id'=>null,'fulfillment_hold'=>0,'hold_reason'=>null,'hold_attempt_id'=>null,'hold_at'=>null];
$cart=['id'=>'51','product_id'=>21,'cart_num'=>1,'productInfo'=>['id'=>21,'mer_id'=>7,
    'attrInfo'=>['id'=>31,'product_id'=>21,'type'=>0,'unique'=>'exit0001']],
    'marketplace'=>['cart_ref'=>'51','sku_id'=>31,'sku_type'=>0,'sku_unique'=>'exit0001','reserve_id'=>'41']];
$item=['id'=>61,'oid'=>11,'uid'=>3,'mer_id'=>7,'cart_id'=>'51','product_id'=>21,'cart_num'=>1,'refund_num'=>0,
    'surplus_num'=>1,'split_status'=>0,'cart_info'=>json_encode($cart,JSON_THROW_ON_ERROR),'unique'=>'exit0001'];
$reserveAction=['contract_version'=>1,'uid'=>3,'mer_id'=>7,'order_id'=>11,'product_id'=>21,'sku_id'=>31,'sku_type'=>0,
    'sku_unique'=>'exit0001','qty'=>1,'kind'=>'reserve','cart_refs'=>['51']];
$reserve=['id'=>'41','mer_id'=>7,'order_id'=>11,'product_id'=>21,'sku_id'=>31,'sku_type'=>0,'sku_unique'=>'exit0001','qty'=>1,
    'kind'=>'reserve','idem_key'=>'reserve:11:31','parent_reserve_id'=>null,'order_item_id'=>null,'refund_id'=>null,
    'actor_type'=>'customer','actor_id'=>3,'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical($reserveAction),true),
    'sku_stock_before'=>5,'sku_stock_after'=>4,'sku_sales_before'=>0,'sku_sales_after'=>1,
    'root_stock_before'=>5,'root_stock_after'=>4,'root_sales_before'=>0,'root_sales_after'=>1,'add_time'=>1700000000];
$releaseAction=['contract_version'=>1,'uid'=>3,'mer_id'=>7,'order_id'=>11,'product_id'=>21,'sku_id'=>31,'sku_type'=>0,
    'sku_unique'=>'exit0001','qty'=>1,'kind'=>'release','parent_reserve_id'=>'41'];
$release=array_replace($reserve,['id'=>'42','kind'=>'release','idem_key'=>'release:11:41','parent_reserve_id'=>'41',
    'actor_type'=>'system','actor_id'=>null,'request_digest'=>hash('sha256',MarketplaceOrderInput::canonical($releaseAction),true),
    'sku_stock_before'=>4,'sku_stock_after'=>5,'sku_sales_before'=>1,'sku_sales_after'=>0,
    'root_stock_before'=>4,'root_stock_after'=>5,'root_sales_before'=>1,'root_sales_after'=>0,'add_time'=>1700000060]);
$statuses=[['oid'=>11,'change_type'=>'cache_key_create_order','change_message'=>'订单生成','change_time'=>1700000000],
    ['oid'=>11,'change_type'=>'cancel_order','change_message'=>'订单取消','change_time'=>1700000060]];
$history=$empty;$history['orders']=[$root];$history['customers']=[['uid'=>3]];$history['ledgers']=[$ledger];
$history['registries']=[$registry];$history['items']=[$item];$history['movements']=[$reserve,$release];$history['statuses']=$statuses;
exitNormalExpect(MarketplaceOrderDao::merchantExitCancelledFromFacts(['customer'=>['uid'=>3],'ledger'=>$ledger,'root'=>$root,
    'items'=>[$item],'movements'=>[$reserve,$release],'statuses'=>$statuses]),'CANCELLED_FULL_NATIVE_FACTS');
$result=MerchantExitFacts::assess($merchant,1700000100,$history);
exitNormalExpect($result['can_close']===true&&array_column($result['responsibilities'],'state')===['CLEAR','CLEAR','CLEAR','CLEAR'],
    'CANCELLED_UNPAID_HISTORY_WITH_TRUE_EMPTY_GENERATION');
$open=$history;$open['orders'][0]['is_cancel']=0;$open['movements']=[$reserve];$open['statuses']=[$statuses[0]];
$result=MerchantExitFacts::assess($merchant,1700000100,$open);
exitNormalExpect($result['can_close']===false&&$result['responsibilities'][0]['state']==='OPEN'
    &&$result['responsibilities'][0]['count']===1,'NORMAL_UNPAID_ORDER_PRECHECK_REPORTS_OPEN');
$rights=$empty;
$rights['legacy_level_definitions']=[['id'=>71,'mer_id'=>7,'money'=>'10.00','is_pay'=>1,'valid_date'=>30,'is_forever'=>0]];
$rights['legacy_user_levels']=[['id'=>81,'uid'=>3,'mer_id'=>7,'level_id'=>71,'valid_time'=>1702592000,'status'=>1,'is_forever'=>0,'is_del'=>0]];
$result=MerchantExitFacts::assess($merchant,1700000100,$rights);
exitNormalExpect($result['can_close']===false&&$result['responsibilities'][3]['state']==='UNKNOWN'
    &&$result['responsibilities'][3]['count']===null,'NORMAL_LEGACY_BENEFITS_PRECHECK_REPORTS_UNKNOWN');
$activity=$empty;$activity['legacy_combinations']=[['id'=>91,'mer_id'=>7,'product_id'=>21,'people'=>2,'is_show'=>1,'is_del'=>0]];
$result=MerchantExitFacts::assess($merchant,1700000100,$activity);
exitNormalExpect($result['can_close']===false&&array_column($result['responsibilities'],'state')===['UNKNOWN','UNKNOWN','UNKNOWN','UNKNOWN'],
    'NORMAL_LEGACY_COMBINATION_PRECHECK_REPORTS_UNKNOWN');
echo json_encode(['schema'=>1,'scope'=>'MERCHANT_EXIT_PURE_NORMAL_FACTS','checks'=>6,'status'=>'PASS',
    'runtime_or_financial_acceptance'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
