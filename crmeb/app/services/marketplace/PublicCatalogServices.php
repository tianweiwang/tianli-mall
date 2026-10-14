<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\services\marketplace\publiccatalog\PublicCatalogProjection as Projection;
use app\services\marketplace\publiccatalog\PublicCatalogQuery;
use app\services\marketplace\publiccatalog\PublicCatalogSku;
use app\services\product\product\StoreProductServices;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use think\db\Query;
use think\facade\Db;
use think\Model;

/** Public entry orchestration only; shared admin/history/raw pricing remain outside it. */
final class PublicCatalogServices
{
    private const NATIVE=[
        1=>['table'=>'store_seckill','model'=>\app\model\activity\seckill\StoreSeckill::class,'flag'=>'status'],
        2=>['table'=>'store_bargain','model'=>\app\model\activity\bargain\StoreBargain::class,'flag'=>'status'],
        3=>['table'=>'store_combination','model'=>\app\model\activity\combination\StoreCombination::class,'flag'=>'is_show'],
        4=>['table'=>'store_integral','model'=>\app\model\activity\integral\StoreIntegral::class,'flag'=>'is_show'],
        6=>['table'=>'store_advance','model'=>\app\model\activity\advance\StoreAdvance::class,'flag'=>'status'],
    ];
    private const NATIVE_CART_ID=[1=>'seckill_id',2=>'bargain_id',3=>'combination_id',6=>'advance_id'];
    public const TEMP_CART_VERSION=1;

    public function __construct(private PublicCatalogQuery $query,private PublicCatalogSku $skus) {}

    public static function merchantFilter(mixed $value):?int
    {
        if($value===null)return null;
        return PublicCatalogQuery::positive($value);
    }
    public function ordinaryRoot(int $id):array
    {
        $root=$this->query->detail($id);
        if($root===null)throw new ApiException('商品已下架或删除');
        return $root;
    }
    public function publishedRoot(int $id):array
    {
        $root=$this->query->publishedRoot($id);
        if($root===null)throw new ApiException('商品已下架或删除');
        return $root;
    }
    /** Same current published base and owner, plus the independent new-order switch. */
    private function currentNewSaleRoot(array $qualifiedRoot):?array
    {
        $id=PublicCatalogQuery::positive($qualifiedRoot['id']??null,8388607);
        $owner=PublicCatalogQuery::positive($qualifiedRoot['mer_id']??null);
        $current=$this->query->purchasableRoot($id);
        if($current!==null&&PublicCatalogQuery::positive($current['mer_id']??null)!==$owner)throw new AuthUnavailableException();
        return $current;
    }
    public function requireNewSale(array $qualifiedRoot):void
    {
        if($this->currentNewSaleRoot($qualifiedRoot)===null)throw new ApiException('商品当前不可购买');
    }
    public function ordinaryPurchaseDefinition(int $id,int $uid,int $cartDisplay=0):array
    {
        $definition=$this->ordinaryDefinition($id,$uid,$cartDisplay);$this->requireNewSale($definition['root']);return $definition;
    }
    public function nativePurchaseBase(int $family,int $nativeId,?int $expectedBase=null):array
    {
        $root=$this->nativeBase($family,$nativeId,$expectedBase);$this->requireNewSale($root);return $root;
    }
    /** Preserve actual root Model getter semantics when using Query's raw array. */
    public function displayRoot(array $root):array
    {
        $root['slider_image']=is_string($root['slider_image']??null)?json_decode($root['slider_image'],true):[];
        $description=app()->make(\app\services\product\product\StoreDescriptionServices::class)->getOne(['product_id'=>(int)$root['id'],'type'=>0]);
        $root['description']=$description?$description->description:'';
        return $root;
    }
    private static function nativeFamily(int $family):array
    {
        if(!isset(self::NATIVE[$family]))throw new AuthUnavailableException();
        return self::NATIVE[$family];
    }
    /** Actual native Model/getters, qualified through strict P(base) before find. */
    public function nativeModel(int $family,int $nativeId,array $with=[]):?Model
    {
        $native=self::nativeFamily($family);PublicCatalogQuery::positive($nativeId);
        $modelClass=$native['model'];$model=new $modelClass();
        $query=$model->db()->alias('catalog_native')->where('catalog_native.id',$nativeId)
            ->where('catalog_native.is_del',0)->where('catalog_native.'.$native['flag'],1);
        $productTable=Db::name('store_product')->getTable();
        $query->whereExists(function(Query $base)use($productTable):void{
            $base->table($productTable)->alias('native_base')->field('id')->whereColumn('native_base.id','catalog_native.product_id');
            $this->query->constrainPublishedRoot($base,'native_base');
        });
        if($with)$query->with($with);
        $row=$query->find();
        if($row!==null&&!($row instanceof Model))throw new AuthUnavailableException();
        return $row;
    }
    public function nativeBase(int $family,int $nativeId,?int $expectedBase=null):array
    {
        $row=$this->nativeModel($family,$nativeId);
        if($row===null)throw new ApiException('活动商品已下架或删除');
        $base=PublicCatalogQuery::positive($row->getData('product_id'),8388607);
        if($expectedBase!==null&&$base!==$expectedBase)throw new AuthUnavailableException();
        return $this->publishedRoot($base);
    }
    /** Apply to actual native list/count builders before any terminal or page operation. */
    public function constrainNative(Query $native,string $alias,int $family,?int $merchantFilter=null):Query
    {
        $spec=self::nativeFamily($family);
        if(preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D',$alias)!==1)throw new AuthUnavailableException();
        $native->where($alias.'.is_del',0)->where($alias.'.'.$spec['flag'],1);
        $productTable=Db::name('store_product')->getTable();
        $native->whereExists(function(Query $base)use($productTable,$alias,$merchantFilter):void{
            $base->table($productTable)->alias('native_base')->field('id')->whereColumn('native_base.id',$alias.'.product_id');
            $this->query->constrainPublishedRoot($base,'native_base',$merchantFilter);
        });
        return $native;
    }
    /** Pure ordinary definition; public read never invokes either legacy definition writer. */
    public function ordinaryDefinition(int $id,int $uid,int $cartDisplay=0):array
    {
        $definition=$this->skus->read($id);
        if($definition===null)throw new ApiException('商品已下架或删除');
        $definition['raw_values']=$this->valueDisplay($definition['raw_values'],$id,$uid,$cartDisplay,$definition['root']);
        return $definition;
    }
    private function valueDisplay(array $values,int $parent,int $uid,int $cartDisplay,array $base):array
    {
        $vip=app()->make(StoreProductServices::class)->vipIsOpen((bool)$base['is_vip']);
        $counts=[];
        if($uid>0&&$cartDisplay){
            $counts=Db::name('store_cart')->where('uid',$uid)->where('product_id',$parent)->where('is_del',0)
                ->where('is_new',0)->where('is_pay',0)->group('product_attr_unique')->column('SUM(cart_num)','product_attr_unique');
        }
        foreach($values as&$row){
            if(!$vip)$row['vip_price']=0;
            if($cartDisplay)$row['cart_num']=$counts[$row['unique']]??0;
        }unset($row);
        return $values;
    }
    /** Native family definition uses native parent/type, never ordinary Sku.read(nativeId). */
    public function nativeDefinition(int $family,int $nativeId,int $baseId,int $uid,int $cartDisplay=0):array
    {
        $base=$this->nativeBase($family,$nativeId,$baseId);
        $rows=Db::name('store_product_attr_value')->where('product_id',$nativeId)->where('type',$family)->where('is_show',1)->order('id','asc')->select()->toArray();
        $axes=Db::name('store_product_attr')->where('product_id',$nativeId)->where('type',$family)->order('id','asc')->select()->toArray();
        $baseStock=Db::name('store_product_attr_value')->where('product_id',$baseId)->where('type',0)->where('is_show',1)->column('stock','suk');
        $values=[];$unique=[];
        foreach($rows as$row){
            PublicCatalogQuery::positive($row['id']);$suk=$row['suk']??null;$key=$row['unique']??null;
            if(!is_string($suk)||$suk===''||!is_string($key)||preg_match('/\A[a-zA-Z0-9]{8}\z/D',$key)!==1||isset($values[$suk])||isset($unique[$key]))throw new AuthUnavailableException();
            $unique[$key]=true;$row['product_stock']=$baseStock[$suk]??0;$values[$suk]=$row;
        }
        $resultRows=Db::name('store_product_attr_result')->where('product_id',$nativeId)->where('type',$family)->column('result');
        $pics=[];
        if(count($resultRows)===1){
            $result=json_decode($resultRows[0],true);
            if(is_array($result)&&is_array($result['attr']??null))foreach($result['attr']as$axis){
                if(!is_array($axis)||!is_string($axis['value']??null)||!is_array($axis['detail']??null))continue;
                foreach($axis['detail']as$option)if(is_array($option)&&is_string($option['value']??null)&&is_string($option['pic']??null))$pics[$axis['value']][$option['value']]=$option['pic'];
            }
        }
        $parts=[];foreach($values as$suk=>$row)$parts[]=explode(',',(string)$suk);
        $attributes=[];$names=[];
        foreach($axes as$index=>$axis){
            $name=$axis['attr_name']??null;
            if(!is_string($name)||$name===''||isset($names[$name])||!is_string($axis['attr_values']??null))throw new AuthUnavailableException();
            $names[$name]=true;$options=explode(',',$axis['attr_values']);$used=[];
            foreach($parts as$part)if(isset($part[$index]))$used[$part[$index]]=true;
            $options=array_values(array_filter($options,static fn(string $option):bool=>$option!==''&&isset($used[$option])));
            $item=['attr_name'=>$name,'attr_values'=>$options,'attr_value'=>[]];
            foreach($options as$option)$item['attr_value'][]=['attr'=>$option,'check'=>false,'pic'=>$pics[$name][$option]??''];
            $attributes[]=$item;
        }
        if(!$axes||!$values)return ['root'=>$base,'attributes'=>[],'raw_values'=>[]];
        foreach($parts as$part){
            if(count($part)!==count($attributes))return ['root'=>$base,'attributes'=>[],'raw_values'=>[]];
            foreach($part as$index=>$option)if(!in_array($option,$attributes[$index]['attr_values'],true))return ['root'=>$base,'attributes'=>[],'raw_values'=>[]];
        }
        return ['root'=>$base,'attributes'=>$attributes,'raw_values'=>$this->valueDisplay($values,$nativeId,$uid,$cartDisplay,$base)];
    }
    /** Display stock is batched over active rows; roots/retired physical stock remain unchanged. */
    public function activeStocks(array $ids):array
    {
        if(!$ids)return [];
        foreach($ids as$id)PublicCatalogQuery::positive($id,8388607);
        return Db::name('store_product_attr_value')->whereIn('product_id',$ids)->where('type',0)->where('is_show',1)->group('product_id')->column('SUM(stock)','product_id');
    }
    public function listOutput(array $list):array
    {
        $stocks=$this->activeStocks(array_column($list,'id'));
        foreach($list as&$row){
            if(isset($row['is_vip'],$row['vip_price'])&&!app()->make(StoreProductServices::class)->vipIsOpen((bool)$row['is_vip']))$row['vip_price']=0;
            $row=Projection::product($row,(int)($stocks[$row['id']]??0));
        }unset($row);
        return $list;
    }
    public function detailOutput(array $data,array $definition):array
    {
        $root=$definition['root'];$data['storeInfo']=Projection::detail($data['storeInfo'],$definition['stock']);
        $data['mer_id']=PublicCatalogQuery::positive($root['mer_id']);$data['store']=Projection::store($root);
        $data['productAttr']=Projection::attributes($data['productAttr']);$data['productValue']=Projection::values($data['productValue']);
        $data['activity']=Projection::activityLinks($data['activity']);
        if(is_array($data['reply']??null))$data['reply']=Projection::take($data['reply'],'reply');
        $data['good_list']=$this->listOutput($data['good_list']);$data['coupons']=Projection::coupons($data['coupons']);
        return array_intersect_key($data,array_fill_keys(['storeInfo','mer_id','store','productAttr','productValue','spec_unique','svip_open','svip_price_open','priceName','reply','replyCount','replyChance','good_list','mapKey','store_self_mention','activity','coupons','routine_contact_type'],true));
    }
    public function activityOutput(array $data,int $family,int $uid):array
    {
        $name=$family===2?'bargain':'storeInfo';$nativeId=PublicCatalogQuery::positive($data[$name]['id']??null);
        return Projection::activityEnvelope($data,$family,$this->nativeBase($family,$nativeId),$uid);
    }
    /** The opaque key is untouched; currentUID/version are explicit server-owned payload fields. */
    public function temporaryCart(array $cart,int $uid):array
    {
        PublicCatalogQuery::positive($uid);
        return ['catalog_cart_version'=>self::TEMP_CART_VERSION,'uid'=>$uid,'cartData'=>$cart];
    }
    public function temporaryCartData(mixed $payload,int $uid):array
    {
        PublicCatalogQuery::positive($uid);
        if(!is_array($payload)||($payload['catalog_cart_version']??null)!==self::TEMP_CART_VERSION||($payload['uid']??null)!==$uid||!is_array($payload['cartData']??null))throw new ApiException('购物车已过期，请重新选择商品');
        $cart=$payload['cartData'];$cart['uid']=$uid;return $cart;
    }
    /** Fresh actual identity authority for current own cart/new quote, never a relation/cache alone. */
    public function cartIdentity(array $cart,int $uid,bool $purchase=true):?array
    {
        PublicCatalogQuery::positive($uid);
        if((int)($cart['uid']??0)!==$uid)throw new AuthUnavailableException();
        $type=(int)($cart['type']??0);$baseId=PublicCatalogQuery::positive($cart['product_id']??null,8388607);
        $unique=$cart['product_attr_unique']??null;
        if(!is_string($unique)||preg_match('/\A[a-zA-Z0-9]{8}\z/D',$unique)!==1)return null;
        if($type===0){
            $definition=$this->skus->read($baseId);$parent=$baseId;
            if($definition===null||!$definition['definition_complete'])return null;
            $root=$definition['root'];$visible=$definition['raw_values'];
        }
        elseif(isset(self::NATIVE_CART_ID[$type])){
            $parent=PublicCatalogQuery::positive($cart[self::NATIVE_CART_ID[$type]]??null);
            $native=$this->nativeModel($type,$parent);if($native===null||(int)$native->getData('product_id')!==$baseId)return null;
            $definition=$this->nativeDefinition($type,$parent,$baseId,$uid);
            $root=$definition['root'];$visible=$definition['raw_values'];
        }else throw new AuthUnavailableException();
        if($purchase)$this->requireNewSale($root);
        $sku=Db::name('store_product_attr_value')->where('product_id',$parent)->where('type',$type)->where('unique',$unique)->where('is_show',1)->find();
        if($sku===null)return null;
        PublicCatalogQuery::positive($sku['id']);
        if(!isset($visible[$sku['suk']])||(int)$visible[$sku['suk']]['id']!==(int)$sku['id'])return null;
        $cached=$cart['attrInfo']??($cart['productInfo']['attrInfo']??null);
        if(is_array($cached)&&isset($cached['id'])&&(int)$cached['id']!==(int)$sku['id'])return null;
        return ['root'=>$root,'sku'=>$sku,'type'=>$type,'parent'=>$parent];
    }
    public function cartOutput(array $cart,int $uid):array
    {
        if(($cart['is_valid']??null)===0&&($cart['productInfo']??null)===[])return Projection::invalidCart($cart);
        $identity=$this->cartIdentity($cart,$uid,false);
        if($identity===null)return Projection::invalidCart($cart);
        return $this->projectCart($cart,$uid,$identity);
    }
    /** The current identity is shared by the public row and its shop grouping. */
    private function projectCart(array $cart,int $uid,array $identity):array
    {
        if(!app()->make(StoreProductServices::class)->vipIsOpen((bool)$identity['root']['is_vip'])){
            if(isset($cart['productInfo']['vip_price']))$cart['productInfo']['vip_price']=0;
            if(isset($cart['productInfo']['attrInfo']['vip_price']))$cart['productInfo']['attrInfo']['vip_price']=0;
        }
        if($identity['type']===0){
            $cart['productInfo']['mer_id']=PublicCatalogQuery::positive($identity['root']['mer_id']);
            $stocks=$this->activeStocks([$identity['root']['id']]);return Projection::cart($cart,(int)($stocks[$identity['root']['id']]??0));
        }
        return Projection::activityCart($cart,$identity['type'],$identity['root'],$uid);
    }
    public function cartRows(array $rows,int $uid):array
    {
        foreach($rows as&$row)$row=$this->cartOutput($row,$uid);unset($row);return $rows;
    }
    /** Preserve database BIGINT references through JSON without floating-point conversion. */
    private static function cartReference(mixed $reference):string
    {
        if(is_int($reference)&&$reference>0)return (string)$reference;
        if(is_string($reference)&&preg_match('/\A[1-9][0-9]*\z/D',$reference)===1)return $reference;
        throw new AuthUnavailableException();
    }
    /** Groups describe this page's own valid rows; they never enable a trade entry. */
    public function cartPageOutput(array $valid,array $invalid,int $uid):array
    {
        $rows=[];$invalidRows=$this->cartRows($invalid,$uid);$groups=[];$groupIndexes=[];
        foreach($invalidRows as&$row)$row['id']=self::cartReference($row['id']??null);unset($row);
        foreach($valid as$cart){
            $cart['id']=self::cartReference($cart['id']??null);
            $identity=$this->cartIdentity($cart,$uid,false);
            if($identity===null||(int)($cart['is_valid']??0)!==1){
                $cart['status']=0;$cart['attrStatus']=false;$cart['is_valid']=0;
                $invalidRows[]=Projection::invalidCart($cart);
                continue;
            }
            $row=$this->projectCart($cart,$uid,$identity);
            unset($row['productInfo']['uid']);
            $owner=PublicCatalogQuery::positive($identity['root']['mer_id']);
            if(!isset($groupIndexes[$owner])){
                $groupIndexes[$owner]=count($groups);
                $groups[]=['mer_id'=>$owner,'store'=>Projection::store($identity['root']),'cart_refs'=>[],'rows'=>[]];
            }
            $index=$groupIndexes[$owner];
            $groups[$index]['cart_refs'][]=$row['id'];$groups[$index]['rows'][]=$row;
            $rows[]=$row;
        }
        return ['valid'=>$rows,'invalid'=>$invalidRows,'groups'=>$groups];
    }
    public function quoteIdentity(array $cartInfo,int $uid):void
    {
        foreach($cartInfo as$cart){
            $identity=$this->cartIdentity($cart,$uid);
            if($identity===null)throw new ApiException('商品已下架或规格已失效');
            $quantity=(int)($cart['cart_num']??0);if($quantity<1||(int)$identity['sku']['stock']<$quantity)throw new ApiException('商品库存不足');
        }
    }
    /** Refresh private calculation input from current root/native and exact active SKU. */
    public function freshCart(array $cart,int $uid,bool $purchase=false):?array
    {
        $identity=$this->cartIdentity($cart,$uid,$purchase);
        if($identity===null)return null;
        $root=$identity['root'];$sku=$identity['sku'];$type=$identity['type'];
        if($type===0)$product=$this->displayRoot($root);
        else{
            $native=$this->nativeModel($type,$identity['parent'],$type===3?['getPrice']:[]);
            if($native===null)return null;
            $product=$native->toArray();
            // Only actual missing fulfillment/form inputs come from the qualified base.
            // Root ordinary presale/activity flags must not change a native transaction's lineage.
            foreach(['mer_id','freight','postage','temp_id','logistics','is_virtual','virtual_type','custom_form','gift_price']as$field)if(!array_key_exists($field,$product)&&array_key_exists($field,$root))$product[$field]=$root[$field];
            $product['store_name']=$product['title'];$product['product_id']=$root['id'];
        }
        $product['mer_id']=$root['mer_id'];
        if($purchase){
            $number=(int)($cart['cart_num']??0);
            if($number<1)throw new ApiException('购买数量错误');
            if($type===0){
                if((int)$sku['stock']<$number)throw new ApiException('商品库存不足');
            }else{
                $nativeServices=match($type){
                    1=>app()->make(\app\services\activity\seckill\StoreSeckillServices::class),
                    2=>app()->make(\app\services\activity\bargain\StoreBargainServices::class),
                    3=>app()->make(\app\services\activity\combination\StoreCombinationServices::class),
                    6=>app()->make(\app\services\activity\advance\StoreAdvanceServices::class),
                    default=>throw new AuthUnavailableException(),
                };
                $checked=match($type){
                    1=>$nativeServices->checkSeckillStock($uid,$identity['parent'],$number,$sku['unique']),
                    2=>$nativeServices->checkBargainStock($uid,$identity['parent'],$number,$sku['unique']),
                    3=>$nativeServices->checkCombinationStock($uid,$identity['parent'],$number,$sku['unique']),
                    6=>$nativeServices->checkAdvanceStock($uid,$identity['parent'],$number,$sku['unique']),
                };
                if((int)$checked[0]['id']!==(int)$sku['id']||$checked[1]!==$sku['unique']||(int)$checked[2]['product_id']!==(int)$root['id'])throw new AuthUnavailableException();
                if($type!==6){
                    $baseStock=Db::name('store_product_attr_value')->where('product_id',$root['id'])->where('type',0)->where('is_show',1)->where('suk',$sku['suk'])->value('stock');
                    if((int)$baseStock<$number)throw new ApiException('商品库存不足');
                }
                if($type===2)$sku['price']=$checked[3]['bargain_price_min'];
            }
        }
        if($type===2){
            $state=app()->make(\app\services\activity\bargain\StoreBargainUserServices::class)->getOne(['uid'=>$uid,'bargain_id'=>$identity['parent'],'status'=>1,'is_del'=>0]);
            if(!$state)return null;
            $sku['price']=$state['bargain_price_min'];
        }
        $product['attrInfo']=$sku;$cart['productInfo']=$product;$cart['attrInfo']=$sku;
        if(!$purchase&&($this->currentNewSaleRoot($root)===null||(int)($type===0?$sku['stock']:$sku['quota'])<(int)($cart['cart_num']??0)))$cart['status']=0;
        return $cart;
    }
    /** Called only by the current-UID namespaced confirmation cache loader. */
    public function currentConfirmationRows(array $rows,int $uid):array
    {
        $result=[];foreach($rows as$cart){
            if(!is_array($cart))throw new AuthUnavailableException();
            if(isset($cart['uid'])&&(int)$cart['uid']!==$uid)throw new AuthUnavailableException();
            $cart['uid']=$uid;$fresh=$this->freshCart($cart,$uid,true);
            if($fresh===null)throw new ApiException('商品已下架或规格已失效');
            $result[]=$fresh;
        }
        $this->ordinaryPurchaseLimits($result,$uid);return $result;
    }
    public function quoteOutput(array $data,int $uid):array
    {
        $data['cartInfo']=$this->cartRows($data['cartInfo'],$uid);
        $data['custom_form']=Projection::formConfig($data['custom_form']);
        if(is_array($data['priceGroup']['cartInfo']??null))$data['priceGroup']['cartInfo']=$this->cartRows($data['priceGroup']['cartInfo'],$uid);
        if(($data['marketplace_confirmation_version']??null)===\app\services\marketplace\order\MarketplaceTradePolicy::CONFIRMATION_VERSION){
            $kind=$data['source_kind']??null;if(!is_string($kind))throw new AuthUnavailableException();
            foreach($data['cartInfo']as&$row)$row['id']=\app\services\marketplace\order\MarketplaceOrderInput::reference($row['id']??null,$kind);unset($row);
            if(is_array($data['priceGroup']['cartInfo']??null)){
                foreach($data['priceGroup']['cartInfo']as&$row)$row['id']=\app\services\marketplace\order\MarketplaceOrderInput::reference($row['id']??null,$kind);unset($row);
            }
        }
        unset($data['priceGroup']['costPrice'],$data['priceGroup']['marketplace_postage_weights'],$data['priceGroup']['selection'],$data['priceGroup']['marketplace_uid']);
        unset($data['costPrice'],$data['marketplace_uid'],$data['selection'],$data['cart_refs'],$data['marketplace_postage_weights']);
        return $data;
    }

    public function nativeListOutput(array $rows,int $family,int $uid):array
    {
        foreach($rows as&$row)$row=Projection::activityRoot($row,$family,$this->nativeBase($family,(int)$row['id']),$uid);
        unset($row);return $rows;
    }

    /** One request timestamp and actual native/config ordering for finite bookmark channels. */
    public function collectionContext(int $uid,?string $category=null,array $relationIds=[]):array
    {
        PublicCatalogQuery::positive($uid);
        if($category===null)$relations=Db::name('store_product_relation')->where('uid',$uid)->where('type','collect')->field(['category','product_id'])->select()->toArray();
        else{$relations=[];foreach($relationIds as$id)$relations[]=['category'=>$category,'product_id'=>PublicCatalogQuery::positive($id,$category==='product'?8388607:4294967295)];}
        $baseIds=[];$seckillIds=[];
        foreach($relations as$row){if($row['category']==='product')$baseIds[]=(int)$row['product_id'];elseif($row['category']==='product_seckill')$seckillIds[]=(int)$row['product_id'];}
        $baseIds=array_values(array_unique($baseIds));$seckillIds=array_values(array_unique($seckillIds));
        $context=['now'=>time(),'seckill_slots'=>[],'advance_ids'=>[]];
        $advances=(new \app\model\activity\advance\StoreAdvance())->db()->alias('collection_advance')->whereIn('collection_advance.product_id',$baseIds);
        $advances=$this->constrainNative($advances,'collection_advance',6)->field(['id','product_id','start_time','stop_time'])->select()->toArray();
        foreach($advances as$native)if($this->bookmarkNativeStructure(6,$native))$context['advance_ids'][]=(int)$native['id'];
        if(!$seckillIds)return $context;
        $slots=$this->configuredSeckillSlots();
        if(!$slots)return $context;
        $query=(new \app\model\activity\seckill\StoreSeckill())->db()->alias('collection_seckill')->whereIn('collection_seckill.id',$seckillIds);
        $natives=$this->constrainNative($query,'collection_seckill',1)->field(['id','product_id','time_id','start_time','stop_time'])->select()->toArray();
        foreach($natives as$native){
            if(!$this->bookmarkNativeStructure(1,$native))continue;
            $declared=explode(',',(string)$native['time_id']);
            foreach($slots as$id=>$unused)if(in_array((string)$id,$declared,true)){$context['seckill_slots'][(int)$native['id']]=$id;break;}
        }return $context;
    }
    /** P and a real destination: O, or qualified native6. Inactive advance never releases O. */
    public function constrainBookmarkBase(Query $base,string $alias,array $context):Query
    {
        $this->query->constrainPublishedRoot($base,$alias);
        $table=Db::name('store_advance')->getTable();
        $base->where(function(Query $channel)use($alias,$table,$context):void{
            $channel->whereNotExists(function(Query $reservation)use($alias,$table,$context):void{
                $reservation->table($table)->alias('bookmark_reservation')->field('id')->whereColumn('bookmark_reservation.product_id',$alias.'.id')->where('bookmark_reservation.is_del',0);
            });
            $channel->whereExists(function(Query $native)use($alias,$table,$context):void{
                $native->table($table)->alias('bookmark_advance')->field('id')->whereColumn('bookmark_advance.product_id',$alias.'.id')->where('bookmark_advance.is_del',0)->where('bookmark_advance.status',1)->whereIn('bookmark_advance.id',$context['advance_ids']);
            },'OR');
        });return $base;
    }
    public function collectionChannel(string $category,int $relationId,array $context):?array
    {
        PublicCatalogQuery::positive($relationId,$category==='product'?8388607:4294967295);
        if($category==='product'){
            $root=$this->query->publishedRoot($relationId);if($root===null)return null;
            if($this->query->detail($relationId)!==null)return ['root'=>$root,'collection_type'=>0,'collection_id'=>$relationId];
            $native=(new \app\model\activity\advance\StoreAdvance())->db()->alias('bookmark_advance')->where('bookmark_advance.product_id',$relationId);
            $row=$this->constrainNative($native,'bookmark_advance',6)->whereIn('bookmark_advance.id',$context['advance_ids'])->order('sort desc,id desc')->find();
            if($row===null)return null;
            return ['root'=>$root,'collection_type'=>6,'collection_id'=>PublicCatalogQuery::positive($row->getData('id'))];
        }
        if($category==='product_seckill'){
            if(!isset($context['seckill_slots'][$relationId]))return null;
            $native=$this->nativeModel(1,$relationId);if($native===null)return null;
            return ['root'=>$this->publishedRoot((int)$native->getData('product_id')),'collection_type'=>1,'collection_id'=>$relationId,'collection_time_id'=>$context['seckill_slots'][$relationId]];
        }
        throw new ApiException('收藏商品类型错误');
    }
    public function requireCollectionChannel(string $category,int $relationId,array $context):array
    {
        $channel=$this->collectionChannel($category,$relationId,$context);
        if($channel===null)throw new ApiException('商品已下架或活动不可用');return $channel;
    }

    /** Pure structural qualification; future/ended/zero-quota detail remains valid. */
    private function bookmarkNativeStructure(int $family,array $native):bool
    {
        try{
            $start=PublicCatalogQuery::positive($native['start_time']??null,PHP_INT_MAX);
            $stop=PublicCatalogQuery::positive($native['stop_time']??null,PHP_INT_MAX);
            if($stop<$start)return false;
            $definition=$this->nativeDefinition($family,(int)$native['id'],(int)$native['product_id'],0);
            return $definition['attributes']!==[]&&$definition['raw_values']!==[];
        }catch(AuthUnavailableException|ApiException $error){return false;}
    }

    /** Integral's actual type4 chain has its own parent and no ordinary confirmation key. */
    public function integralIdentity(string $unique,int $uid,int $number):array
    {
        if(preg_match('/\A[a-zA-Z0-9]{8}\z/D',$unique)!==1||$number<1)throw new ApiException('请选择有效的商品属性');
        $rows=Db::name('store_product_attr_value')->where('type',4)->where('unique',$unique)->where('is_show',1)->select()->toArray();
        if(count($rows)!==1)throw new ApiException('商品规格已失效');$sku=$rows[0];
        $nativeId=PublicCatalogQuery::positive($sku['product_id']);$base=$this->nativeBase(4,$nativeId);
        $definition=$this->nativeDefinition(4,$nativeId,(int)$base['id'],$uid);$this->requireNewSale($definition['root']);
        if(!isset($definition['raw_values'][$sku['suk']])||(int)$definition['raw_values'][$sku['suk']]['id']!==(int)$sku['id'])throw new ApiException('商品规格已失效');
        app()->make(\app\services\activity\integral\StoreIntegralServices::class)->checkoutProductStock($uid,$nativeId,$number,$unique);
        $stock=Db::name('store_product_attr_value')->where('product_id',$base['id'])->where('type',0)->where('is_show',1)->where('suk',$sku['suk'])->value('stock');
        if((int)$stock<$number)throw new ApiException('商品库存不足');
        return ['root'=>$base,'sku'=>$sku,'native_id'=>$nativeId];
    }
    public function integralConfirmOutput(array $data,int $uid):array
    {
        $raw=$data['productInfo'];$identity=$this->integralIdentity($raw['unique'],$uid,(int)$data['num']);
        if((int)$raw['product_id']!==$identity['native_id'])throw new AuthUnavailableException();
        $out=Projection::take($raw,'integral-confirm-sku');
        $native=$raw['storeIntegral']??null;
        if(!is_array($native)||(int)$native['id']!==$identity['native_id'])throw new AuthUnavailableException();
        $out['storeIntegral']=Projection::take($native,'integral-confirm-native');$data['productInfo']=$out;
        return array_intersect_key($data,array_fill_keys(['integral','num','total_price','productInfo'],true));
    }
    public function replyRows(array $rows):array
    {
        foreach($rows as&$row)$row=Projection::take($row,'reply');unset($row);return $rows;
    }

    /** Only confirmed ordinary product snapshot slots; arbitrary JSON IDs are untouched. */
    private function hydrateOrdinarySnapshot(array $saved,int $uid):array
    {
        $ids=[];foreach($saved as$row){if(!is_array($row)||!isset($row['id']))continue;$ids[]=PublicCatalogQuery::positive($row['id'],8388607);}
        if(!$ids)return [];
        $products=app()->make(StoreProductServices::class);
        $rows=$products->getPublicSearchList(['ids'=>array_values(array_unique($ids))],0,0);
        foreach($rows as&$row){
            if(!$products->vipIsOpen((bool)$row['is_vip']))$row['vip_price']=0;
            $row['cart_button']=$row['is_virtual']||$row['virtual_type']==3||$row['presale']||json_decode($row['custom_form'],true)?0:1;
        }unset($row);
        $rows=$products->getProduceOtherList($products->publicListDisplay($rows),$uid);
        $rows=$this->listOutput($rows);$byId=array_column($rows,null,'id');$out=[];
        foreach($ids as$id)if(isset($byId[$id]))$out[]=$byId[$id];return $out;
    }
    private function hydrateProductComponent(array $component,int $uid):array
    {
        $name=$component['name']??'';
        if(!in_array($name,['goodList','promotionList'],true))return $component;
        foreach(['goodsList','productList']as$slot)if(is_array($component[$slot]['list']??null))$component[$slot]['list']=$this->hydrateOrdinarySnapshot($component[$slot]['list'],$uid);
        if($name==='promotionList'&&is_array($component['tabConfig']['list']??null)){
            foreach($component['tabConfig']['list']as&$tab)if(is_array($tab)&&is_array($tab['goodsList']['list']??null))$tab['goodsList']['list']=$this->hydrateOrdinarySnapshot($tab['goodsList']['list'],$uid);
            unset($tab);
        }return $component;
    }
    public function hydrateConfiguration(array $configuration,int $uid):array
    {
        if(isset($configuration['value'])&&is_array($configuration['value'])){
            foreach($configuration['value']as&$component)if(is_array($component))$component=$this->hydrateProductComponent($component,$uid);
            unset($component);return $configuration;
        }
        foreach($configuration as$key=>&$components){
            if(!is_array($components))continue;
            if(isset($components['name']))$components=$this->hydrateProductComponent($components,$uid);
            elseif(in_array($key,['goodList','promotionList'],true))foreach($components as&$component){
                if(!is_array($component))continue;$name=$component['name']??null;
                if($name===null)$component['name']=$key;
                $component=$this->hydrateProductComponent($component,$uid);
                if($name===null)unset($component['name']);
            }
            unset($component);
        }unset($components);return $configuration;
    }

    /** Quote quantities are an order; existing own basket rows are not counted a second time. */
    private function ordinaryPurchaseLimits(array $rows,int $uid):void
    {
        $groups=[];
        foreach($rows as$cart)if((int)$cart['type']===0){$id=(int)$cart['product_id'];$groups[$id]['root']=$cart['productInfo'];$groups[$id]['number']=($groups[$id]['number']??0)+(int)$cart['cart_num'];}
        foreach($groups as$id=>$entry){
            $root=$entry['root'];$number=$entry['number'];
            if(empty($root['is_limit']))continue;
            if((int)$root['limit_type']===1&&$number>(int)$root['limit_num'])throw new ApiException('单次购买数量不能大于 {:limit} 件',['limit'=>$root['limit_num']]);
            if((int)$root['limit_type']===2){
                $history=app()->make(\app\services\order\StoreOrderCartInfoServices::class);
                $paid=$history->sum(['uid'=>$uid,'product_id'=>$id,'split_status'=>0],'cart_num');$refund=$history->sum(['uid'=>$uid,'product_id'=>$id,'split_status'=>0],'refund_num');
                if($number+$paid-$refund>(int)$root['limit_num'])throw new ApiException('该商品限购 {:limit} 件，您已经购买 {:pay_num} 件',['limit'=>$root['limit_num'],'pay_num'=>$paid-$refund]);
            }
        }
    }

    /** Real current group order; raw time and decoded producer values must agree. */
    private function configuredSeckillSlots():array
    {
        $configs=app()->make(\app\services\system\config\SystemGroupDataServices::class)->getConfigNameValue('routine_seckill_time');
        $raw=Db::name('system_group_data')->whereIn('id',array_column($configs,'id'))->where('status',1)->column('value','id');
        $slots=[];
        foreach($configs as$config){
            $id=PublicCatalogQuery::positive($config['id']);$value=json_decode($raw[$id]??'',true);
            if(!is_array($value)||!isset($value['time']['value'],$value['continued']['value']))continue;
            $hour=$value['time']['value'];$duration=$value['continued']['value'];
            if(!is_scalar($hour)||!is_scalar($duration)||preg_match('/\A(?:[0-9]|1[0-9]|2[0-3])\z/D',(string)$hour)!==1||preg_match('/\A(?:[1-9]|1[0-9]|2[0-4])\z/D',(string)$duration)!==1)continue;
            if((string)($config['time']??'')!==(string)$hour||(string)($config['continued']??'')!==(string)$duration)throw new AuthUnavailableException();
            $slots[$id]=$config;
        }
        return $slots;
    }
    /** Exact declared/configured slot, never an implicit default0 replacement. */
    public function seckillSlot(int $nativeId,int $slotId):array
    {
        if($slotId<1)throw new ApiException('活动时段不存在');
        $native=$this->nativeModel(1,$nativeId);
        if($native===null)throw new ApiException('活动商品已下架或删除');
        $declared=explode(',',(string)$native->getData('time_id'));
        $slots=$this->configuredSeckillSlots();
        if(!in_array((string)$slotId,$declared,true)||!isset($slots[$slotId]))throw new ApiException('活动时段不存在');
        return $slots[$slotId];
    }
    /** Existing PC QR has no slot parameter: finite actual declared/configured order. */
    public function canonicalSeckillSlot(int $nativeId):int
    {
        $native=$this->nativeModel(1,$nativeId);
        if($native===null)throw new ApiException('活动商品已下架或删除');
        $declared=explode(',',(string)$native->getData('time_id'));
        foreach($this->configuredSeckillSlots()as$id=>$config)if(in_array((string)$id,$declared,true))return $id;
        throw new ApiException('活动时段不存在');
    }

}
