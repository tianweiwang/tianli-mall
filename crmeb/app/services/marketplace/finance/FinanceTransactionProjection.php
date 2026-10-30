<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use app\services\marketplace\payment\MarketplacePaymentServices;
use crmeb\exceptions\AuthUnavailableException;

/** Saved test facts only. No driver, ledger, settlement or rule activation. */
final class FinanceTransactionProjection
{
    public static function rule(array $draft): array
    {
        $draft=FinanceSettingsInput::draft($draft);
        return array_intersect_key($draft,array_flip(['platform_fee_enabled','platform_fee_basis','platform_fee_rate','platform_fee_payer']));
    }

    public static function estimate(array $draft,string $goods,string $shipping): ?array
    {
        $rule=self::rule($draft);$goods=self::cents($goods);$shipping=self::cents($shipping);
        if($rule['platform_fee_enabled']===null)return null;
        if($rule['platform_fee_enabled']===false)return ['basis_amount'=>null,'platform_fee_amount'=>'0.00','payer'=>$rule['platform_fee_payer']];
        if($rule['platform_fee_basis']===null||$rule['platform_fee_rate']===null)return null;
        $basis=$goods+($rule['platform_fee_basis']==='order_paid'?$shipping:0);
        // DECIMAL(12,2) * hundredths-of-a-percent stays inside a signed 64-bit integer.
        $rate=(int)str_replace('.','',$rule['platform_fee_rate']);
        $fee=intdiv($basis*$rate+5000,10000);
        return ['basis_amount'=>self::amount($basis),'platform_fee_amount'=>self::amount($fee),'payer'=>$rule['platform_fee_payer']];
    }

    /** Observation is the original DAO's fully validated complete same-root collection. */
    public static function row(array $header,array $observation,?array $draft): array
    {
        if(($observation['root_payment_consistent']??null)!==true)throw new AuthUnavailableException();
        $locked=$observation['locked'];$root=$locked['root'];
        $paid=self::cents($root['pay_price']);$shipping=self::cents($header['pay_postage']);$goods=self::cents($header['total_price']);
        if($goods+$shipping!==$paid)throw new AuthUnavailableException();
        $attempts=[];$applied=false;
        foreach($locked['attempts'] as $attempt){
            $state=MarketplacePaymentServices::savedAttemptState($attempt);
            if($state==='applied')$applied=true;
            $attempts[]=['attempt_id'=>$attempt['id'],'admitted_generation'=>$attempt['admitted_generation'],
                'created_at'=>$attempt['created_at'],'state'=>$state,'applied_at'=>$attempt['evidence']['application']['at']??null];
        }
        $hold=$locked['registry']['fulfillment_hold']===1;$estimate=null;
        if($draft===null)$status='NO_REVISION';
        elseif(!$applied)$status='NOT_APPLIED';
        elseif($hold)$status='PAYMENT_HOLD';
        else{$estimate=self::estimate($draft,self::amount($goods),self::amount($shipping));$status=$estimate===null?'RULE_INCOMPLETE':'ESTIMATED';}
        return ['root_order_id'=>$root['id'],'order_no'=>$root['order_id'],'mer_id'=>$root['mer_id'],'currency'=>'CNY',
            'order_amount'=>self::amount($paid),'goods_paid'=>self::amount($goods),'shipping_paid'=>self::amount($shipping),
            'fulfillment_hold'=>$hold,'attempts'=>$attempts,'estimate_status'=>$status,'fee_estimate'=>$estimate];
    }

    private static function cents(string $amount): int
    {
        if(PHP_INT_SIZE<8||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$amount)!==1)throw new AuthUnavailableException();
        return (int)str_replace('.','',$amount);
    }
    private static function amount(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
}
