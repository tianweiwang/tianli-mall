<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\services\marketplace\PublicCatalogServices;
use app\services\marketplace\publiccatalog\PublicCatalogProjection;
use app\services\marketplace\publiccatalog\PublicCatalogQuery;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;

/** Captures and rechecks actual own cart rows, never client identities or cached prices. */
final class MarketplaceCartSelection
{
    public function __construct(private PublicCatalogServices $catalog) {}

    public static function frame(array $group,int $uid):array
    {
        if(($group['marketplace_confirmation_version']??null)!==MarketplaceTradePolicy::CONFIRMATION_VERSION
            ||($group['marketplace_uid']??null)!==$uid)throw new AuthUnavailableException();
        $owner=PublicCatalogQuery::positive($group['mer_id']??null);
        $kind=$group['source_kind']??null;
        if(!is_string($kind)||!in_array($kind,['persistent','temporary'],true))throw new AuthUnavailableException();
        $refs=$group['cart_refs']??null;$selection=$group['selection']??null;
        if(!is_array($refs)||!array_is_list($refs)||!$refs||!is_array($selection)||!array_is_list($selection)||count($selection)!==count($refs))throw new AuthUnavailableException();
        foreach($refs as$ref)if(!is_string($ref)||MarketplaceOrderInput::reference($ref,$kind)!==$ref)throw new AuthUnavailableException();
        if(MarketplaceOrderInput::references(implode(',',$refs),$kind)!==$refs)throw new AuthUnavailableException();
        return ['marketplace_confirmation_version'=>MarketplaceTradePolicy::CONFIRMATION_VERSION,'marketplace_uid'=>$uid,
            'mer_id'=>$owner,'source_kind'=>$kind,'cart_refs'=>$refs,'selection'=>$selection];
    }

    public function capture(int $uid,int $merId,string $sourceKind,array $refs,array $rows):array
    {
        PublicCatalogQuery::positive($uid);PublicCatalogQuery::positive($merId);
        if(!$rows||count($rows)!==count($refs))throw new ApiException('购物车选择已变化，请重新确认');
        $selection=[];$store=null;
        foreach($rows as$cart){
            if(!is_array($cart))throw new AuthUnavailableException();
            if(MarketplaceOrderInput::unsigned($cart['type']??0,'商品类型')!==0)MarketplaceTradePolicy::denyFeature('activity');
            $ref=MarketplaceOrderInput::reference($sourceKind==='temporary'?($cart['marketplace_cart_ref']??null):($cart['id']??null),$sourceKind);
            if($sourceKind==='temporary'&&MarketplaceOrderInput::reference($cart['id']??null,$sourceKind)!==$ref)throw new AuthUnavailableException();
            if(!in_array($ref,$refs,true)||isset($selection[$ref]))throw new ApiException('购物车选择已变化，请重新确认');
            $identity=$this->catalog->cartIdentity($cart,$uid,true);
            if($identity===null)throw new ApiException('商品已下架或规格已失效');
            if(PublicCatalogQuery::positive($identity['root']['mer_id'])!==$merId)throw new ApiException('请选择同一家店铺的商品');
            $quantity=MarketplaceOrderInput::unsigned($cart['cart_num']??0,'商品数量');
            if($quantity===0)throw new ApiException('购买数量错误');
            $selection[$ref]=['cart_ref'=>$ref,'product_id'=>PublicCatalogQuery::positive($identity['root']['id'],8388607),
                'sku_id'=>PublicCatalogQuery::positive($identity['sku']['id'],2147483647),'sku_type'=>0,
                'unique'=>$identity['sku']['unique'],'qty'=>$quantity];
            if($store===null)$store=PublicCatalogProjection::store($identity['root']);
        }
        $ordered=[];foreach($refs as$ref){if(!isset($selection[$ref]))throw new ApiException('购物车选择已变化，请重新确认');$ordered[]=$selection[$ref];}
        return ['marketplace_confirmation_version'=>MarketplaceTradePolicy::CONFIRMATION_VERSION,'marketplace_uid'=>$uid,
            'mer_id'=>$merId,'source_kind'=>$sourceKind,'cart_refs'=>$refs,'selection'=>$ordered,'store'=>$store];
    }

    public function recheck(array $group,int $uid,array $rows):array
    {
        $frozen=self::frame($group,$uid);
        $current=$this->capture($uid,$frozen['mer_id'],$frozen['source_kind'],$frozen['cart_refs'],$rows);
        if($current['selection']!==$frozen['selection'])throw new ApiException('购物车选择已变化，请重新确认');
        return $current;
    }
}
