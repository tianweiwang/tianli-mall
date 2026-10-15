<?php
declare(strict_types=1);

// Pure ordinary product-definition/answer examples. No UID, actor, App, SQL or cache is constructed.
require dirname(__DIR__, 3).'/crmeb/vendor/autoload.php';

use app\services\marketplace\order\MarketplaceOrderCustomForm as Form;
use app\services\marketplace\order\MarketplaceOrderInput as Input;

$assertions = 0;
$check = static function (bool $ok) use (&$assertions): void {
    if (!$ok) throw new RuntimeException('MS4_ORDINARY_CUSTOM_FORM');
    ++$assertions;
};
$field = static fn(string $title, string $label, bool $required): array =>
    ['title'=>$title,'label'=>$label,'value'=>'','status'=>$required];
$products = [
    12=>['id'=>12,'store_name'=>'普通商品乙','custom_form'=>json_encode([
        $field('说明','text',true),$field('数量','number',false),$field('附件','img',true)], JSON_THROW_ON_ERROR)],
    7=>['id'=>7,'store_name'=>'普通商品甲','custom_form'=>json_encode([
        $field('说明','text',true),$field('说明','data',true),$field('时间','time',true),
        $field('编号','id',true),$field('数量','number',true),$field('邮箱','email',true),
        $field('电话','phone',true),$field('备注','email',false)], JSON_THROW_ON_ERROR)],
];
$selection = [['cart_ref'=>'2','product_id'=>12],['cart_ref'=>'9007199254740993','product_id'=>7],
    ['cart_ref'=>'18446744073709551615','product_id'=>7]];
$definitions = Form::definitions($products,$selection,'persistent');
$check(array_column($definitions,'product_id') === [7,12]);
$check($definitions[0]['cart_refs'] === ['9007199254740993','18446744073709551615']);
$check(count($definitions[0]['fields']) === 8 && $definitions[0]['fields'][0]['title'] === $definitions[0]['fields'][1]['title']);
$check($definitions[0]['fields'][1]['label'] === 'data' && $definitions[1]['fields'][2]['value'] === []);
$check(Form::configuration($definitions,$selection,'persistent') === $definitions);
$answers = [
    ['product_id'=>12,'definition_sha256'=>$definitions[1]['definition_sha256'],'values'=>[
        ['field_index'=>2,'value'=>['ordinary-one.png','ordinary-two.png']],['field_index'=>0,'value'=>'乙的说明'],['field_index'=>1,'value'=>'0']]],
    ['product_id'=>7,'definition_sha256'=>$definitions[0]['definition_sha256'],'values'=>[
        ['field_index'=>7,'value'=>'保留 optional 原值'],['field_index'=>6,'value'=>'13800138000'],
        ['field_index'=>5,'value'=>'ordinary@example.invalid'],['field_index'=>4,'value'=>'0001.000000000000000001'],
        ['field_index'=>3,'value'=>'原始 / 编号'],['field_index'=>2,'value'=>'12:30'],
        ['field_index'=>1,'value'=>'2026-10-15'],['field_index'=>0,'value'=>'甲的说明']]],
];
$normalized = Form::answers($answers);
$check(array_column($normalized,'product_id') === [7,12] && array_column($normalized[0]['values'],'field_index') === range(0,7));
$check(Form::answers(array_reverse($answers)) === $normalized);
$check(Input::canonical(['custom_form'=>$normalized]) === Input::canonical(['custom_form'=>Form::answers($answers)]));
$snapshot = Form::snapshot($definitions,$normalized,$products,$selection,'persistent');
$check($snapshot[0]['fields'][0]['value'] === '甲的说明' && $snapshot[0]['fields'][1]['value'] === '2026-10-15');
$check($snapshot[1]['fields'][0]['value'] === '乙的说明' && $snapshot[1]['fields'][1]['value'] === '0');
$check($snapshot[0]['fields'][4]['value'] === '0001.000000000000000001');
$check($snapshot[0]['fields'][7]['value'] === '保留 optional 原值');
$check($snapshot[1]['fields'][2]['value'] === ['ordinary-one.png','ordinary-two.png']);
$check(strlen(Form::encoded($snapshot)) <= 65535 && json_decode(Form::encoded($snapshot),true,32,JSON_THROW_ON_ERROR) === $snapshot);
// Repricing for another saved address/invoice must carry answers, without requiring all required fields filled yet.
$retained = Form::retainAnswers($definitions,$normalized,Form::definitions($products,$selection,'persistent'));
$check($retained === $normalized && Form::snapshot($definitions,$retained,$products,$selection,'persistent') === $snapshot);
$editing = $normalized; $editing[0]['values'][0]['value'] = '';
$check(Form::retainAnswers($definitions,$editing,$definitions) === $editing);
$singleSelection = [['cart_ref'=>'temporary-ordinary','product_id'=>12]];
$single = Form::definitions([12=>$products[12]],$singleSelection,'temporary');
$check(count($single) === 1 && $single[0]['cart_refs'] === ['temporary-ordinary']);
$singleAnswers = [$normalized[1]];
$check(Form::snapshot($single,$singleAnswers,[12=>$products[12]],$singleSelection,'temporary')[0]['fields'][0]['value'] === '乙的说明');
$plain = [3=>['id'=>3,'store_name'=>'普通无表单商品','custom_form'=>'']];
$plainSelection = [['cart_ref'=>'1','product_id'=>3]];
$check(Form::definitions($plain,$plainSelection,'persistent') === []);
$check(Form::snapshot([],[],$plain,$plainSelection,'persistent') === []);
$plain[3]['custom_form'] = '[]';
$check(Form::definitions($plain,$plainSelection,'persistent') === []);
$check(Form::configuration($definitions,array_reverse($selection),'persistent') === $definitions);
$ten = array_fill(0,10,$field('同名说明','text',false));
$manyProducts = [90=>['id'=>90,'store_name'=>'十字段普通商品甲','custom_form'=>json_encode($ten,JSON_THROW_ON_ERROR)],
    91=>['id'=>91,'store_name'=>'十字段普通商品乙','custom_form'=>json_encode($ten,JSON_THROW_ON_ERROR)]];
$manySelection = [['cart_ref'=>'10','product_id'=>90],['cart_ref'=>'11','product_id'=>91]];
$manyDefinitions = Form::definitions($manyProducts,$manySelection,'persistent'); $manyAnswers = [];
foreach ($manyDefinitions as $definition) {
    $values = []; foreach ($definition['fields'] as $f) $values[] = ['field_index'=>$f['field_index'],'value'=>''];
    $manyAnswers[] = ['product_id'=>$definition['product_id'],'definition_sha256'=>$definition['definition_sha256'],'values'=>$values];
}
$manySnapshot = Form::snapshot($manyDefinitions,$manyAnswers,$manyProducts,$manySelection,'persistent');
$check(count($manySnapshot) === 2 && count($manySnapshot[0]['fields'])+count($manySnapshot[1]['fields']) === 20);
$check($manySnapshot[0]['fields'][9]['value'] === '' && $manySnapshot[1]['fields'][9]['title'] === '同名说明');
// Ordinary nonempty Unicode near-capacity data is preserved byte-for-byte, never truncated.
$large = $snapshot; $large[0]['fields'][0]['value'] = str_repeat('中',20000);
$check(json_decode(Form::encoded($large),true,32,JSON_THROW_ON_ERROR)[0]['fields'][0]['value'] === str_repeat('中',20000));
$boundary = $snapshot; $boundary[0]['fields'][0]['value'] = '';
$room = 65535-strlen(Form::encoded($boundary));
$boundary[0]['fields'][0]['value'] = str_repeat('中',intdiv($room,3)).str_repeat('a',$room%3);
$check(strlen(Form::encoded($boundary)) === 65535);
$check(json_decode(Form::encoded($boundary),true,32,JSON_THROW_ON_ERROR) === $boundary);
echo json_encode(['status'=>'PASS','scope'=>'PURE_ORDINARY_PRODUCT_FORMS','assertions'=>$assertions,
    'App_SQL_auth_cache_HTTP_or_stopped_samples_run'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
