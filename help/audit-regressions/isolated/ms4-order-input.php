<?php
declare(strict_types=1);

// Pure wire examples only: numeric values do not establish actors or database identities.
require dirname(__DIR__,3).'/crmeb/vendor/autoload.php';

use app\services\marketplace\order\MarketplaceOrderInput;

$assertions=0;
$check=static function(bool $ok)use(&$assertions):void{
    if(!$ok)throw new RuntimeException('MS4_ORDINARY_INPUT_ENCODING');
    ++$assertions;
};
$raw=['cartId'=>'18446744073709551615,2,9007199254740993,2','mer_id'=>'23','idem_key'=>'ordinary-wire-A',
    'addressId'=>'16777215','new'=>0,'invoice_id'=>'','couponId'=>0,'useIntegral'=>false,
    'bargainId'=>'','shipping_type'=>1,'store_id'=>0,'real_name'=>'普通联系人','phone'=>'contact-wire',
    'gift_mark'=>'普通页面同时携带的留言','mark'=>'备注<&> 保留原字节',
    'custom_form'=>[['product_id'=>7,'definition_sha256'=>str_repeat('a',64),'values'=>[
        ['field_index'=>0,'value'=>'中文与 / 路径'],['field_index'=>1,'value'=>['image-a.png','image-b.png']]]]],
    'formId'=>'','discountId'=>0,'from'=>'h5','quitUrl'=>'unused-local-path'];
$first=MarketplaceOrderInput::create(17,'ordinary-confirmation-key',$raw);
$request=$first['request'];
$check($request['contract_version']===1&&$request['uid']===17&&$request['mer_id']===23);
$check($request['source_kind']==='persistent');
$check($request['cart_refs']===['2','9007199254740993','18446744073709551615']);
$check($request['address_id']===16777215);
$check($request['options']['invoice_id']===0&&$request['options']['bargainId']===0);
$check($request['options']['payType']===''&&$request['options']['is_gift']===0);
$check($request['options']['real_name']===$raw['real_name']&&$request['options']['gift_mark']===$raw['gift_mark']);
$check($first['idem_key']==='ordinary-wire-A'&&!array_key_exists('idem_key',$request));
$bytes=MarketplaceOrderInput::canonical($request);
$check(str_contains($bytes,'备注<&> 保留原字节')&&str_contains($bytes,'中文与 / 路径'));
$check(!str_contains($bytes,'quitUrl')&&!str_contains($bytes,'formId')&&!str_contains($bytes,'discountId'));
$check(strlen(hash('sha256',$bytes,true))===32&&strlen(hash('sha256',$request['confirm_key'],true))===32);

$reordered=array_reverse($raw,true);
$reordered['cartId']='9007199254740993,18446744073709551615,2';
$reordered['idem_key']='ordinary-wire-B';
unset($reordered['formId'],$reordered['discountId'],$reordered['from'],$reordered['quitUrl']);
$second=MarketplaceOrderInput::create(17,'ordinary-confirmation-key',$reordered);
$check(MarketplaceOrderInput::canonical($second['request'])===$bytes);
$objectOrder=array_reverse($request,true);
$objectOrder['options']=array_reverse($request['options'],true);
$objectOrder['options']['custom_form'][0]=array_reverse($request['options']['custom_form'][0],true);
$check(MarketplaceOrderInput::canonical($objectOrder)===$bytes);
$check(json_decode($bytes,true,32,JSON_THROW_ON_ERROR)['options']['custom_form'][0]['values'][1]['value']===['image-a.png','image-b.png']);

$temporary=['cartId'=>'temp-b,temp-A','mer_id'=>23,'idem_key'=>'ordinary-temporary',
    'is_new'=>'1','addressId'=>1,'invoice_id'=>'2147483647','payType'=>'WeIxIn','store_id'=>'2147483647'];
$third=MarketplaceOrderInput::create(17,'ordinary-temporary-key',$temporary)['request'];
$check($third['source_kind']==='temporary'&&$third['cart_refs']===['temp-A','temp-b']);
$check($third['options']['payType']==='weixin');
$check($third['options']['invoice_id']===2147483647&&$third['options']['store_id']===2147483647);
$check($third['options']['custom_form']===[]);
$check(MarketplaceOrderInput::canonical(['b'=>null,'a'=>true,'list'=>['first','second']])==='{"a":true,"b":null,"list":["first","second"]}');
echo json_encode(['status'=>'PASS','scope'=>'pure_ordinary_wire_encoding','assertions'=>$assertions,
    'App_SQL_auth_cache_payment_or_stopped_samples_run'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
