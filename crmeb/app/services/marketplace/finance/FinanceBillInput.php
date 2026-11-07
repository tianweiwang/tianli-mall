<?php
declare(strict_types=1);
namespace app\services\marketplace\finance;

use app\services\marketplace\MerchantManagementInput;
use app\services\marketplace\payment\MarketplaceRefundEvidence;
use DateTimeImmutable;
use DateTimeZone;
use think\exception\HttpException;

final class FinanceBillInput
{
    public static function period(array $input):array
    {
        $out=['anchor_attempt_id'=>MarketplaceRefundEvidence::u64($input['anchor_attempt_id']??null)];
        $zone=new DateTimeZone('Asia/Shanghai');
        foreach(['date_from','date_to'] as $key){
            $text=$input[$key]??null;
            if(!is_string($text)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$text)!==1)
                throw new HttpException(422,'请选择完整的账单起止日期');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$text,$zone);
            if($date===false||$date->format('Y-m-d')!==$text)throw new HttpException(422,'账单日期不正确');
            $out[$key]=$text;$out[$key==='date_from'?'period_start':'period_end']=$date->getTimestamp()+($key==='date_to'?86400:0);
        }
        if($out['period_start']<1||$out['period_end']>4294967295||$out['period_end']<=$out['period_start']
            ||$out['period_end']-$out['period_start']>31*86400)throw new HttpException(422,'账单期间须为1至31天');
        return $out;
    }

    public static function import(array $body):array
    {
        MerchantManagementInput::body($body,['anchor_attempt_id','date_from','date_to','filename','csv'],
            ['anchor_attempt_id','date_from','date_to','filename','csv']);
        $period=self::period($body);$filename=MerchantManagementInput::text($body['filename'],120,true);
        if(preg_match('/[\x00-\x1f\x7f\\\\\/]/u',$filename)===1||!is_string($body['csv']))
            throw new HttpException(422,'账单文件名或内容不正确');
        $parsed=FinanceBillReconciliation::parse($body['csv']);
        return $period+['filename'=>$filename,'csv'=>$body['csv'],'parsed'=>$parsed];
    }
}
