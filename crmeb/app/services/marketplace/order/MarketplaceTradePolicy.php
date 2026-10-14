<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use think\facade\Config;

/** New confirmation policy only; saved historical orders are not upgraded by ownership. */
final class MarketplaceTradePolicy
{
    public const CONFIRMATION_VERSION=1;
    private const FEATURES=['coupon','integral','balance','brokerage','activity'];

    public static function active():bool
    {
        $enabled=Config::get('marketplace.enabled',false);
        if(!is_bool($enabled))throw new AuthUnavailableException();
        return $enabled;
    }

    private static function disabled():array
    {
        $features=Config::get('marketplace.trade.disabled_features',self::FEATURES);
        if(!is_array($features)||!array_is_list($features))throw new AuthUnavailableException();
        foreach($features as$feature)if(!is_string($feature)||!in_array($feature,self::FEATURES,true))throw new AuthUnavailableException();
        return $features;
    }

    public static function denyFeature(string $feature):never
    {
        if(in_array($feature,self::disabled(),true))throw new ApiException('当前商城交易未启用'.$feature);
        throw new ApiException('当前普通交易尚不支持'.$feature);
    }

    public static function preview(array $raw,array $normalized):void
    {
        self::disabled();
        foreach(self::FEATURES as$feature)if(array_key_exists($feature,$raw)&&!in_array($raw[$feature],[null,false,0,'0',''],true))self::denyFeature($feature);
        if($normalized['shipping_type']!==1||$normalized['is_gift']!==0)throw new ApiException('当前普通确认仅支持快递配送');
        if(MarketplaceOrderInput::unsigned($raw['couponId']??0,'couponId')!==0)self::denyFeature('coupon');
        if(MarketplaceOrderInput::boolean($raw['useIntegral']??0,'useIntegral'))self::denyFeature('integral');
        $payType=$raw['payType']??'';
        if(!is_string($payType))throw new ApiException('payType格式错误');
        if(strtolower($payType)==='yue')self::denyFeature('balance');
        foreach(['combinationId','pinkId','seckill_id','secKillId','bargainId','advanceId']as$field){
            $value=$raw[$field]??0;if($value==='')$value=0;
            if(MarketplaceOrderInput::unsigned($value,$field)!==0)self::denyFeature('activity');
        }
    }

    public static function savedOrderIsLegacy(array $order):bool
    {
        $version=$order['marketplace_trade_version']??null;
        if($version===null||$version===0||$version==='0')return true;
        if($version===1||$version==='1')return false;
        throw new AuthUnavailableException();
    }

    /** No fallthrough to legacy creation, even if the unfinished feature is enabled by mistake. */
    public static function guardCreate():never
    {
        $enabled=Config::get('marketplace.trade.enabled',false);
        if(!is_bool($enabled))throw new AuthUnavailableException();
        if(!$enabled)throw new ApiException('当前商城下单尚未开放');
        throw new ApiException('单商家订单创建尚未完成');
    }
}
