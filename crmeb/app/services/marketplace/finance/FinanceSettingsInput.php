<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use app\services\marketplace\MerchantManagementInput;
use think\exception\HttpException;

/** Draft rules only: no default fee, account binding or payment capability is inferred. */
final class FinanceSettingsInput
{
    public const FIELDS = ['contracting_entity','payment_provider','payment_product','account_model',
        'platform_fee_enabled','aftersale_hold','platform_fee_payer','channel_fee_payer',
        'platform_fee_basis','platform_fee_rate','settlement_trigger','settlement_cycle',
        'settlement_delay_days','refund_platform_fee'];
    private const LABELS = ['contracting_entity'=>'签约经营主体','payment_provider'=>'支付服务商',
        'payment_product'=>'收款产品','account_model'=>'商家收款账户方案','platform_fee_enabled'=>'是否收取平台费',
        'aftersale_hold'=>'进行中售后是否暂缓结算','platform_fee_payer'=>'平台费承担方',
        'channel_fee_payer'=>'通道费承担方','platform_fee_basis'=>'平台费计费基数','platform_fee_rate'=>'平台费率',
        'settlement_trigger'=>'结算触发条件','settlement_cycle'=>'结算周期',
        'settlement_delay_days'=>'结算等待天数','refund_platform_fee'=>'退款时平台费处理'];
    private const OPTIONS = ['account_model'=>['merchant_direct','provider_submerchant'],
        'platform_fee_payer'=>['merchant','platform'],'channel_fee_payer'=>['merchant','platform'],
        'platform_fee_basis'=>['goods_paid','order_paid'],'settlement_trigger'=>['receipt','aftersale_end'],
        'settlement_cycle'=>['daily','weekly','monthly'],'refund_platform_fee'=>['proportional','none']];

    public static function emptyDraft(): array { return array_fill_keys(self::FIELDS,null); }

    public static function draft(mixed $value): array
    {
        if (!is_array($value)) self::invalid();
        MerchantManagementInput::body($value,self::FIELDS,self::FIELDS);
        $out=[];
        foreach (self::FIELDS as $field) {
            $item=$value[$field];
            if ($item===null) { $out[$field]=null; continue; }
            if (in_array($field,['contracting_entity','payment_provider','payment_product'],true)) {
                if (!is_string($item)||preg_match('//u',$item)!==1) self::invalid();
                // Match ECMAScript String.trim, including BOM but excluding U+0085.
                $space='[\x09-\x0d\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]';
                $item=preg_replace('/\A'.$space.'+|'.$space.'+\z/u','',$item);
                $item=MerchantManagementInput::text($item,120);
                if (preg_match('/[\x00-\x1f\x7f]/u',$item)===1) self::invalid();
                $out[$field]=$item===''?null:$item;
            } elseif (isset(self::OPTIONS[$field])) {
                if (!is_string($item)||!in_array($item,self::OPTIONS[$field],true)) self::invalid();
                $out[$field]=$item;
            } elseif (in_array($field,['platform_fee_enabled','aftersale_hold'],true)) {
                if (!is_bool($item)) self::invalid();
                $out[$field]=$item;
            } elseif ($field==='settlement_delay_days') {
                $out[$field]=MerchantManagementInput::integer($item,0,365);
            } elseif ($field==='platform_fee_rate') {
                if (!is_string($item)||preg_match('/\A(?:0|[1-9][0-9]?|100)\.[0-9]{2}\z/D',$item)!==1
                    ||(int)str_replace('.','',$item)>10000) self::invalid();
                $out[$field]=$item;
            } else self::invalid();
        }
        return $out;
    }

    public static function saveBody(array $body): array
    {
        MerchantManagementInput::body($body,['expected_revision','draft'],['expected_revision','draft']);
        return ['expected_revision'=>MerchantManagementInput::integer($body['expected_revision'],0,4294967295),
            'draft'=>self::draft($body['draft'])];
    }

    public static function missingFields(array $draft): array
    {
        $draft=self::draft($draft);$out=[];
        foreach (self::FIELDS as $field) {
            if ($draft['platform_fee_enabled']===false
                &&in_array($field,['platform_fee_payer','platform_fee_basis','platform_fee_rate'],true)) continue;
            if ($draft[$field]===null) $out[]=['field'=>$field,'label'=>self::LABELS[$field]];
        }
        return $out;
    }

    public static function changedFields(array $before,array $after): array
    {
        $before=self::draft($before);$after=self::draft($after);$out=[];
        foreach (self::FIELDS as $field) if ($before[$field]!==$after[$field]) $out[]=$field;
        return $out;
    }

    public static function encode(array $value): string
    { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }

    private static function invalid(): never { throw new HttpException(422,'资金设置草稿格式不正确'); }
}
