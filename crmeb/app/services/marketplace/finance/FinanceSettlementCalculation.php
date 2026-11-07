<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use think\exception\HttpException;

/** Cumulative order figures only. A saved version, reconciliation, eligibility and settlement record are separate. */
final class FinanceSettlementCalculation
{
    public static function calculate(array $draft,array $amounts):array
    {
        $draft=FinanceSettingsInput::draft($draft);
        $keys=array_keys($amounts);sort($keys);
        $expected=['goods_paid','shipping_paid','goods_refunded','shipping_refunded','channel_fee'];sort($expected);
        if($keys!==$expected)throw new HttpException(422,'结算计算金额字段不正确');
        $values=[];
        foreach(['goods_paid','shipping_paid','goods_refunded','shipping_refunded'] as $field)
            $values[$field]=FinanceBillReconciliation::cents($amounts[$field]);
        $channelFee=$amounts['channel_fee']===null?null:FinanceBillReconciliation::cents($amounts['channel_fee'],true);
        $sales=$values['goods_paid']+$values['shipping_paid'];
        $refund=$values['goods_refunded']+$values['shipping_refunded'];
        if($sales>999999999999||$values['goods_refunded']>$values['goods_paid']||$values['shipping_refunded']>$values['shipping_paid'])
            throw new HttpException(422,'退款金额超过对应实付金额');
        $missing=[];$fee=null;$reversal=null;
        $required=['platform_fee_enabled','channel_fee_payer'];
        if($draft['platform_fee_enabled']===true)$required=array_merge($required,
            ['platform_fee_basis','platform_fee_rate','platform_fee_payer','refund_platform_fee']);
        foreach($required as $field)if($draft[$field]===null)$missing[]=$field;
        if($draft['platform_fee_enabled']===false){$fee=0;$reversal=0;}
        elseif($draft['platform_fee_enabled']===true&&$draft['platform_fee_basis']!==null&&$draft['platform_fee_rate']!==null){
            $basis=$values['goods_paid']+($draft['platform_fee_basis']==='order_paid'?$values['shipping_paid']:0);
            $refundBasis=$values['goods_refunded']+($draft['platform_fee_basis']==='order_paid'?$values['shipping_refunded']:0);
            $rate=(int)str_replace('.','',$draft['platform_fee_rate']);
            $fee=intdiv($basis*$rate+5000,10000);
            if($draft['refund_platform_fee']==='none')$reversal=0;
            elseif($draft['refund_platform_fee']==='proportional'){
                // Multiplication of two DECIMAL(12,2) cent amounts can exceed int64; BCMath keeps it exact.
                $reversal=$basis===0?0:(int)bcdiv(bcadd(bcmul((string)$fee,(string)$refundBasis,0),(string)intdiv($basis,2),0),(string)$basis,0);
            }
        }
        $netFee=$fee===null||$reversal===null?null:$fee-$reversal;
        $receivable=null;
        if($missing===[]&&$netFee!==null&&($draft['channel_fee_payer']==='platform'||$channelFee!==null)){
            $merchantPlatformFee=$draft['platform_fee_enabled']===true&&$draft['platform_fee_payer']==='merchant'?$netFee:0;
            $merchantChannelFee=$draft['channel_fee_payer']==='merchant'?$channelFee:0;
            $receivable=$sales-$refund-$merchantPlatformFee-$merchantChannelFee;
        }
        $format=static fn(?int $value):?string=>$value===null?null:FinanceBillReconciliation::amount($value);
        return ['schema'=>1,'scope'=>'cumulative_fee_calculation_only','real_funds_enabled'=>false,
            'status'=>$missing!==[]?'RULE_INCOMPLETE':($channelFee===null?'CHANNEL_FEE_UNKNOWN':'CALCULATED_NOT_SETTLED'),
            'missing_fields'=>$missing,'sales_amount'=>$format($sales),'refund_amount'=>$format($refund),
            'channel_fee_amount'=>$format($channelFee),'platform_fee_amount'=>$format($fee),
            'platform_fee_reversal'=>$format($reversal),'platform_fee_net'=>$format($netFee),
            'merchant_receivable'=>$format($receivable),'settled_amount'=>null];
    }
}
