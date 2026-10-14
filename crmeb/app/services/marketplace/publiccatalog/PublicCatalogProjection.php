<?php
declare(strict_types=1);
namespace app\services\marketplace\publiccatalog;

use crmeb\exceptions\AuthUnavailableException;
use InvalidArgumentException;

/** Fixed consumer projections. Internal costing/pricing structures remain untouched. */
final class PublicCatalogProjection
{
    private const PRODUCT=['id','mer_id','store_name','store_info','image','slider_image','small_image','image_base','price','ot_price','vip_price','unit_name','sales','fsales','stock','spec_type','is_postage','postage','is_vip','video_link','min_qty','limit_num','is_virtual','virtual_type','presale','presale_start_time','presale_end_time','presale_day','presale_pay_status','cart_button','cart_num','is_att','labels','label_list','activity','star','checkCoupon','cate_name'];
    // Native raw summary/cart roots have no final label or activity display conversion.
    private const ROOT_SUMMARY=['id','mer_id','store_name','store_info','image','slider_image','small_image','image_base','price','ot_price','vip_price','unit_name','sales','fsales','stock','spec_type','is_postage','postage','is_vip','video_link','min_qty','limit_num','is_virtual','virtual_type','presale','presale_start_time','presale_end_time','presale_day','presale_pay_status','cart_button','cart_num','is_att'];
    private const DETAIL=['description','default_sku','unique','params_list','protection_list','userCollect','userLike','command_word','wechat_code','attrPics','svip_economize_price','is_gift'];
    private const ACTIVITY_ROOT=['id','product_id','title','info','description','image','images','small_image','image_base','store_name','store_info','unit_name','price','ot_price','stock','product_stock','quota','quota_show','num','total','sales','status','unique','userCollect','userLike','wechat_code','code_base','product_is_show'];
    private const ACTIVITY_ADDITIONS=[
        1=>['once_num','last_time','product_price','start_time','stop_time','percent','uid'],
        2=>['min_price','rule','time','stop_time','attr'],
        3=>['people','product_price','start_time','stop_time','sale_stock'],
        4=>['product_price','once_num','sale_stock'],
        6=>['spec_type','is_vip','vip_price','start_time','stop_time','deliver_time','command_word','percent'],
    ];
    private const ACTIVITY_ENVELOPE=[
        1=>['storeInfo','buy_num','reply','replyChance','replyCount','productAttr','productValue','routine_contact_type'],
        2=>['bargain','userInfo','userBargainInfo'],
        3=>['storeInfo','pink_ok_list','pink_ok_sum','pink','pinkAll','buy_num','reply','replyChance','replyCount','productAttr','productValue','routine_contact_type'],
        4=>['storeInfo','productAttr','productValue','routine_contact_type'],
        6=>['pay_status','buy_num','storeInfo','reply','replyChance','replyCount','productAttr','productValue','routine_contact_type'],
    ];
    private const RESOURCES=[
        'merchant'=>['mer_id','name','logo','after_sale_contact','shipping_note'],
        'sku'=>['id','product_id','type','unique','suk','image','price','ot_price','vip_price','stock','cart_num'],
        'activity-sku'=>['id','product_id','type','unique','suk','image','price','ot_price','vip_price','stock','cart_num','product_stock','quota','quota_show','num','once_num','vipPrice'],
        'attribute'=>['attr_name','attr_values','attr_value'], 'attribute-option'=>['attr','check','pic'],
        'label'=>['name','font_color','bg_color','border_color','image'], 'protection'=>['id','title','content','image'], 'parameter'=>['name','value'],
        'activity-link'=>['type','id','time_id'], 'star'=>['product_score','product_id'],
        'real-price'=>['real_price','price','is_vip','product_is_vip','member_price','level_price','user_is_member','ot_price'],
        'reply'=>['id','product_id','nickname','avatar','is_money_level','star','add_time','suk','comment','pics','merchant_reply_content','merchant_reply_time'],
        'cart'=>['id','type','product_id','product_attr_unique','cart_num','status','attrStatus','is_valid','trueStock','truePrice','vip_truePrice','sum_price','price_type','seckill_id','bargain_id','combination_id','advance_id','min_qty','productInfo'],
        'collection'=>['pid','product_id','category','store_name','price','ot_price','sales','image','is_del','is_show','is_fail','collection_type','collection_id','collection_time_id'],
        'integral-confirm-sku'=>['id','product_id','type','unique','suk','image','price','store_name'],
        'integral-confirm-native'=>['id','store_name'],
        'activity-list-1'=>['id','product_id','mer_id','title','info','image','price','ot_price','product_price','stock','quota','quota_show','percent','unit_name','sales','num','once_num','start_time','stop_time','cate_id'],
        'activity-list-2'=>['id','product_id','mer_id','title','min_price','image','price','stock','ot_price','product_price','cate_id','people'],
        'activity-list-3'=>['id','product_id','mer_id','image','title','info','price','product_price','ot_price','stock','quota','quota_show','people','sales','unit_name','start_time','stop_time','num','once_num'],
        'activity-list-4'=>['id','product_id','mer_id','image','title','price','sales','stock'],
        'form-config'=>['title','label','value','status'],
        'coupon-display'=>['id','title','type','receive_type','receive_limit','coupon_price','use_min_price','coupon_time','start_use_time','end_use_time','start_time','end_time','is_use'],
        'bargain-state'=>['userBargainStatus','count','price','status','alreadyPrice','pricePercent','bargainOrderCount','bargainCount','bargainType'],
        'pink-display'=>['id','nickname','avatar','count','people','stop_time','h','i','s'],
        'current-viewer'=>['uid','nickname','avatar'],
        'invalid-cart'=>['id','type','product_id','product_attr_unique','cart_num','status','attrStatus','is_valid','productInfo'],
    ];
    public static function fields(string $resource):array
    {
        foreach(self::ACTIVITY_ADDITIONS as$family=>$fields){
            if($resource==='activity-root-'.$family)return array_merge(self::ACTIVITY_ROOT,$fields,['mer_id']);
            if($resource==='activity-envelope-'.$family)return array_merge(self::ACTIVITY_ENVELOPE[$family],['mer_id','store']);
        }
        if($resource==='product'||$resource==='product-list')return self::PRODUCT;
        if($resource==='product-detail')return array_merge(array_diff(self::PRODUCT,['activity','star']),self::DETAIL);
        if($resource==='product-sku-summary')return array_merge(self::ROOT_SUMMARY,['default_sku','unique']);
        if($resource==='cart-product')return array_merge(self::ROOT_SUMMARY,['limit_type','store_mention']);
        if(!isset(self::RESOURCES[$resource]))throw new InvalidArgumentException('Unknown public catalog resource');
        return self::RESOURCES[$resource];
    }
    public static function take(array $source,string $resource):array
    {
        return array_intersect_key($source,array_fill_keys(self::fields($resource),true));
    }
    private static function items(mixed $value,string $resource):array
    {
        if($value===null||$value==='')return [];
        if(!is_array($value)||!array_is_list($value))throw new AuthUnavailableException();
        $out=[];foreach($value as $item){if(!is_array($item))throw new AuthUnavailableException();$out[]=self::take($item,$resource);}return $out;
    }
    private static function root(array $raw,int $activeStock,string $resource):array
    {
        if($activeStock<0||$activeStock>16777215)throw new AuthUnavailableException();
        $out=self::take($raw,$resource);$out['id']=PublicCatalogQuery::positive($raw['id']??null,8388607);$out['mer_id']=PublicCatalogQuery::positive($raw['mer_id']??null);$out['stock']=$activeStock;
        return $out;
    }
    /** Final ordinary list only: native label/activity/BC-star presenters must have run. */
    public static function product(array $raw,int $activeStock,bool $detail=false):array
    {
        if($detail)return self::detail($raw,$activeStock);
        $out=self::root($raw,$activeStock,'product-list');
        foreach(['labels'=>'label','label_list'=>'label','protection_list'=>'protection','params_list'=>'parameter'] as $field=>$resource)if(array_key_exists($field,$out))$out[$field]=self::items($out[$field],$resource);
        if(isset($out['activity'])){if(!is_array($out['activity']))throw new AuthUnavailableException();$out['activity']=array_is_list($out['activity'])?self::items($out['activity'],'activity-link'):self::take($out['activity'],'activity-link');}
        if(array_key_exists('star',$out)&&(!is_string($out['star'])||preg_match('/\A-?(?:0|[1-9][0-9]*)\.[0-9]\z/D',$out['star'])!==1))throw new AuthUnavailableException();
        return $out;
    }
    /** Final storeInfo: display lists are decoded; activity belongs to the separate envelope. */
    public static function detail(array $raw,int $activeStock):array
    {
        $out=self::root($raw,$activeStock,'product-detail');
        foreach(['labels'=>'label','label_list'=>'label','protection_list'=>'protection','params_list'=>'parameter'] as $field=>$resource)if(array_key_exists($field,$out))$out[$field]=self::items($out[$field],$resource);
        return $out;
    }
    /** V2 get_attr root summary, independent of the ordinary-detail spec0 presentation. */
    public static function skuSummary(array $raw,int $activeStock):array
    {
        return self::root($raw,$activeStock,'product-sku-summary');
    }
    /** Current detail data.activity list, already resolved to native activity IDs. */
    public static function activityLinks(array $rawLinks):array
    {
        return self::items($rawLinks,'activity-link');
    }
    public static function store(array $rawRoot):array
    {
        $owner=PublicCatalogQuery::positive($rawRoot['mer_id']??null);$actual=PublicCatalogQuery::positive($rawRoot['_public_store_mer_id']??null);if($actual!==$owner)throw new AuthUnavailableException();
        $out=['mer_id'=>$actual];foreach(['name','logo','after_sale_contact','shipping_note'] as $name){$value=$rawRoot['_public_store_'.$name]??null;if(!is_string($value))throw new AuthUnavailableException();$out[$name]=$value;}return $out;
    }
    public static function attributes(array $attributes):array
    {
        $out=[];foreach($attributes as $attribute){if(!is_array($attribute))throw new AuthUnavailableException();$item=self::take($attribute,'attribute');$item['attr_value']=self::items($attribute['attr_value']??[],'attribute-option');$out[]=$item;}return $out;
    }
    /** Preserve actual suk dictionary keys, after current-UID/member producers have run. */
    public static function values(array $rawValues,bool $activity=false):array
    {
        $out=[];foreach($rawValues as $key=>$row){$suk=is_array($row)?($row['suk']??null):null;if(!is_string($suk)||(string)$key!==$suk)throw new AuthUnavailableException();$out[$key]=self::take($row,$activity?'activity-sku':'sku');}return $out;
    }
    /** After handleCartList/current-UID pricing: never feed raw CSV into final-list checks. */
    public static function cartProduct(array $rawProduct,int $activeStock):array
    {
        $out=self::root($rawProduct,$activeStock,'cart-product');
        if(array_key_exists('store_mention',$out)&&!is_bool($out['store_mention']))throw new AuthUnavailableException();
        if(isset($rawProduct['attrInfo'])){if(!is_array($rawProduct['attrInfo']))throw new AuthUnavailableException();$out['attrInfo']=self::take($rawProduct['attrInfo'],'activity-sku');}
        return $out;
    }
    public static function cart(array $rawCart,int $activeStock):array
    {
        $out=self::take($rawCart,'cart');$product=$rawCart['productInfo']??null;if(!is_array($product))throw new AuthUnavailableException();$out['productInfo']=self::cartProduct($product,$activeStock);
        return $out;
    }
    /** Final flat collection row; caller applies eligibility before its SQL count/page. */
    public static function collection(array $rawCollection):array
    {
        $out=self::take($rawCollection,'collection');PublicCatalogQuery::positive($out['pid']??null,8388607);
        $type=$out['collection_type']??null;
        if(!is_int($type)||!in_array($type,[0,1,6],true))throw new AuthUnavailableException();
        PublicCatalogQuery::positive($out['collection_id']??null,$type===0?8388607:4294967295);
        if($type===1)PublicCatalogQuery::positive($out['collection_time_id']??null);
        elseif(isset($out['collection_time_id']))throw new AuthUnavailableException();
        if(!in_array($out['category']??null,['product','product_seckill'],true))throw new AuthUnavailableException();
        PublicCatalogQuery::positive($out['product_id']??null,$out['category']==='product'?8388607:4294967295);
        return $out;
    }
    /** Only decoded current confirmation configuration, never filled/paid order form values. */
    public static function formConfig(array $rawConfiguration):array
    {
        if(!array_is_list($rawConfiguration)||count($rawConfiguration)>10)throw new AuthUnavailableException();
        $out=[];foreach($rawConfiguration as$row){
            if(!is_array($row))throw new AuthUnavailableException();$item=self::take($row,'form-config');
            if(count($item)!==4||!is_string($item['title'])||!is_string($item['label'])||!is_bool($item['status'])
                ||!in_array($item['label'],['text','number','email','data','time','id','phone','img'],true))throw new AuthUnavailableException();
            $value=$item['value'];
            if($item['label']==='img'){
                if($value!==''){
                    if(!is_array($value)||!array_is_list($value))throw new AuthUnavailableException();
                    foreach($value as$image)if(!is_string($image))throw new AuthUnavailableException();
                }
            }elseif(!is_string($value))throw new AuthUnavailableException();
            $out[]=$item;
        }
        return $out;
    }
    /** Called only after actual native lineage, publication and family state validation. */
    public static function activityRoot(array $raw,int $family,array $publishedBase,int $currentUid):array
    {
        if(!isset(self::ACTIVITY_ADDITIONS[$family])||$currentUid<0)throw new AuthUnavailableException();
        $baseId=PublicCatalogQuery::positive($publishedBase['id']??null,8388607);
        if(PublicCatalogQuery::positive($raw['product_id']??null,8388607)!==$baseId)throw new AuthUnavailableException();
        $out=self::take($raw,'activity-root-'.$family);
        $out['id']=PublicCatalogQuery::positive($raw['id']??null);
        $out['product_id']=$baseId;
        $out['mer_id']=PublicCatalogQuery::positive($publishedBase['mer_id']??null);
        if($family===1)$out['uid']=$currentUid;
        if(isset($out['attr'])){if(!is_array($out['attr']))throw new AuthUnavailableException();$out['attr']=self::take($out['attr'],'activity-sku');}
        foreach(['images']as$field)if(array_key_exists($field,$out)){
            if(!is_array($out[$field])||!array_is_list($out[$field]))throw new AuthUnavailableException();
            foreach($out[$field]as$url)if(!is_string($url))throw new AuthUnavailableException();
        }
        return $out;
    }
    /** Family envelopes are separate from ordinary detail and preserve native list/dict forms. */
    public static function activityEnvelope(array $raw,int $family,array $publishedBase,int $currentUid):array
    {
        if(!isset(self::ACTIVITY_ENVELOPE[$family]))throw new AuthUnavailableException();
        $out=self::take($raw,'activity-envelope-'.$family);$name=$family===2?'bargain':'storeInfo';
        if(!is_array($out[$name]??null))throw new AuthUnavailableException();
        $out[$name]=self::activityRoot($out[$name],$family,$publishedBase,$currentUid);
        if(isset($out['productAttr']))$out['productAttr']=self::attributes($out['productAttr']);
        if(isset($out['productValue']))$out['productValue']=self::values($out['productValue'],true);
        if(is_array($out['reply']??null))$out['reply']=self::take($out['reply'],'reply');
        if($family===2){
            if(!is_array($out['userInfo']??null)||!is_array($out['userBargainInfo']??null))throw new AuthUnavailableException();
            $out['userInfo']=self::take($out['userInfo'],'current-viewer');
            $out['userInfo']['uid']=$currentUid;
            $out['userBargainInfo']=self::take($out['userBargainInfo'],'bargain-state');
        }
        if($family===3){
            if(isset($out['pink']))$out['pink']=self::items($out['pink'],'pink-display');
            foreach(['pinkAll','pink_ok_list']as$key)if(isset($out[$key])&&(!is_array($out[$key])||!array_is_list($out[$key])))throw new AuthUnavailableException();
            foreach($out['pinkAll']??[]as$id)PublicCatalogQuery::positive($id);
            foreach($out['pink_ok_list']??[]as$message)if(!is_string($message))throw new AuthUnavailableException();
        }
        $out['mer_id']=PublicCatalogQuery::positive($publishedBase['mer_id']??null);
        $out['store']=self::store($publishedBase);
        return $out;
    }
    public static function invalidCart(array $raw):array
    {
        $out=self::take($raw,'invalid-cart');$out['productInfo']=[];return $out;
    }
    public static function coupons(array $rows):array
    {
        return self::items($rows,'coupon-display');
    }
    public static function activityCart(array $raw,int $family,array $publishedBase,int $currentUid):array
    {
        $out=self::take($raw,'cart');$product=$raw['productInfo']??null;
        if(!is_array($product))throw new AuthUnavailableException();
        $out['productInfo']=self::activityRoot($product,$family,$publishedBase,$currentUid);
        if(isset($product['attrInfo'])){if(!is_array($product['attrInfo']))throw new AuthUnavailableException();$out['productInfo']['attrInfo']=self::take($product['attrInfo'],'activity-sku');}
        return $out;
    }
}
