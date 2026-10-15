<?php
declare(strict_types=1);

// Ordinary saved DTO data only. No UID/auth actor, App, database, cache or transport is constructed.
require dirname(__DIR__,3).'/crmeb/vendor/autoload.php';

use app\services\marketplace\order\MarketplaceOrderSnapshotProjection as Saved;

$checks=0;
$check=static function(bool $ok)use(&$checks):void{if(!$ok)throw new RuntimeException('MS4_ORDINARY_SAVED_SNAPSHOT');++$checks;};
$cart=static fn(int $product,string $ref,string $name):array=>[
    'id'=>$ref,'type'=>0,'product_id'=>$product,'cart_num'=>2,'unique'=>str_repeat('c',32),'truePrice'=>'12.34',
    'sum_true_price'=>'24.68','postage_price'=>'1.01','origin_postage_price'=>'1.01','costPrice'=>'3.21',
    'marketplace'=>['reserve_id'=>'18446744073709551615'],'marketplace_postage_weights'=>['private'],
    'productInfo'=>['id'=>$product,'mer_id'=>3,'store_name'=>$name,'image'=>'saved.png','unit_name'=>'件','price'=>'15.00','cost'=>'3.21',
        'stock'=>90,'attrInfo'=>['id'=>$product+5,'product_id'=>$product,'type'=>0,'unique'=>'sku'.$product,'suk'=>'原规格','image'=>'sku.png',
            'price'=>'15.00','cost'=>'3.21','stock'=>90]]];
$forms=[['product_id'=>7,'product_name'=>'原商品甲','cart_refs'=>['18446744073709551615'],
    'definition_sha256'=>str_repeat('a',64),'fields'=>[
        ['field_index'=>0,'title'=>'同名资料','label'=>'text','status'=>true,'value'=>'保存的答案'],
        ['field_index'=>1,'title'=>'同名资料','label'=>'data','status'=>false,'value'=>'2026-10-15'],
        ['field_index'=>2,'title'=>'附件','label'=>'img','status'=>true,'value'=>['saved-one.png','saved-two.png']]]],
    ['product_id'=>8,'product_name'=>'原商品乙','cart_refs'=>['opaque-ref'],'definition_sha256'=>str_repeat('b',64),
        'fields'=>[['field_index'=>0,'title'=>'同名资料','label'=>'number','status'=>false,'value'=>'0']]]];
$raw=['marketplace_trade_version'=>1,'id'=>19,'order_id'=>'ordinary-order','is_gift'=>0,'paid'=>0,
    'pay_price'=>'51.38','pay_postage'=>'2.02','total_price'=>'49.36','custom_form'=>json_encode($forms,JSON_THROW_ON_ERROR),
    'cartInfo'=>[$cart(7,'18446744073709551615','原商品甲'),$cart(8,'opaque-ref','原商品乙')],
    'refund_cartInfo'=>[$cart(7,'18446744073709551615','原商品甲'),$cart(8,'opaque-ref','原商品乙')],
    'cost'=>'6.42','user'=>['private'=>'profile'],'_info'=>[['cart_info'=>['private'=>'raw']]],
    '_status'=>['_type'=>0,'_title'=>'待支付','_msg'=>'请完成支付','private'=>'hidden'],
    'invoice'=>['id'=>4,'name'=>'保存抬头','pay_price'=>'51.38','private'=>'hidden'],
    'help_info'=>['help_status'=>0,'pay_nickname'=>'','pay_avatar'=>'','private'=>'hidden']];
$before=serialize($raw);$out=Saved::customerOrder($raw);
$check(serialize($raw)===$before);
$check($out['marketplace_trade_version']===1 && $out['order_id']==='ordinary-order');
$check(!isset($out['cost'],$out['user'],$out['_info']));
$check(!array_key_exists('cost',$out)&&!array_key_exists('user',$out)&&!array_key_exists('_info',$out));
$check($out['cartInfo'][0]['id']==='18446744073709551615');
$check(array_intersect(['costPrice','marketplace','marketplace_postage_weights'],array_keys($out['cartInfo'][0]))===[]);
$check(!array_key_exists('cost',$out['cartInfo'][0]['productInfo'])&&!array_key_exists('stock',$out['cartInfo'][0]['productInfo'])
    &&!array_key_exists('cost',$out['cartInfo'][0]['productInfo']['attrInfo'])&&!array_key_exists('stock',$out['cartInfo'][0]['productInfo']['attrInfo']));
$check($out['cartInfo'][0]['productInfo']['store_name']==='原商品甲' && $out['cartInfo'][0]['productInfo']['attrInfo']['suk']==='原规格');
$check($out['cartInfo'][0]['postage_price']==='1.01' && $out['cartInfo'][0]['origin_postage_price']==='1.01');
$check($out['refund_cartInfo']===$out['cartInfo']);
$check($out['custom_form'][0]['fields'][0]['value']==='保存的答案' && $out['custom_form'][0]['definition_sha256']===str_repeat('a',64));
$check(!array_key_exists('cart_refs',$out['custom_form'][0]));
$check($out['custom_form'][0]['fields'][0]['title']===$out['custom_form'][0]['fields'][1]['title']);
$check($out['custom_form'][0]['fields'][2]['value']===['saved-one.png','saved-two.png']);
$check($out['custom_form'][1]['fields'][0]['value']==='0');
$check($out['_status']===['_type'=>0,'_title'=>'待支付','_msg'=>'请完成支付']);
$check($out['invoice']===['id'=>4,'name'=>'保存抬头','pay_price'=>'51.38']);
$refund=['order_id'=>'ordinary-refund','store_order_id'=>19,'cart_info'=>[$cart(8,'opaque-ref','原商品乙')],
    'cartInfo'=>[$cart(8,'opaque-ref','原商品乙')],'custom_form'=>[],'refund_price'=>'24.68','cost'=>'private'];
$refundOut=Saved::customerRefund($refund,['marketplace_trade_version'=>'1','custom_form'=>json_encode($forms,JSON_THROW_ON_ERROR)]);
$check($refundOut['marketplace_trade_version']===1 && count($refundOut['custom_form'])===1 && $refundOut['custom_form'][0]['product_id']===8);
$check($refundOut['cart_info']===$refundOut['cartInfo'] && $refundOut['cartInfo'][0]['id']==='opaque-ref');
$check(!array_key_exists('cost',$refundOut) && !array_key_exists('marketplace',$refundOut['cartInfo'][0]));
$legacy=['marketplace_trade_version'=>null,'custom_form'=>[['title'=>'旧字段','value'=>'旧答案']],'cartInfo'=>[['costPrice'=>'old']]];
$check(Saved::customerOrder($legacy)===$legacy);
$check(Saved::customerRefund($legacy,['marketplace_trade_version'=>0])===$legacy);
$check(Saved::customForms('[]')===[]);
$check(json_decode(json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR)===$out);
$platform=$raw+['cart_info'=>$raw['cartInfo'],'is_all_refund'=>false,'refunded_price'=>'0.00','one_brokerage'=>'3.21',
    'refund'=>[['id'=>29,'order_id'=>'saved-refund','store_order_id'=>19,'refund_type'=>1,'refund_num'=>1,'refund_price'=>'12.34',
        'cart_info'=>[$cart(7,'18446744073709551615','原商品甲')],'cost'=>'private']]];
$platformBefore=serialize($platform);$staff=Saved::platformOrder($platform);
$check($staff['is_all_refund']===false && $staff['refunded_price']==='0.00');
$check($staff['cartInfo']===$staff['cart_info'] && $staff['cartInfo']===$staff['refund_cartInfo']);
$check(!array_key_exists('_info',$staff)&&!array_key_exists('cost',$staff)&&!array_key_exists('one_brokerage',$staff));
$check($staff['refund']===[['id'=>29,'order_id'=>'saved-refund','store_order_id'=>19,'refund_type'=>1,'refund_num'=>1,'refund_price'=>'12.34']]);
$check($staff['custom_form']===$out['custom_form'] && serialize($platform)===$platformBefore);
$check(Saved::platformRefund($refund,['marketplace_trade_version'=>1,'custom_form'=>json_encode($forms,JSON_THROW_ON_ERROR)])===$refundOut);
$check(Saved::platformOrder($legacy)===$legacy);
$check(Saved::platformRefund($legacy,['marketplace_trade_version'=>0])===$legacy);
echo json_encode(['status'=>'PASS','scope'=>'PURE_ORDINARY_SAVED_SNAPSHOT','assertions'=>$checks,
    'App_SQL_auth_cache_HTTP_or_stopped_samples_run'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
