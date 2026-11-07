<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use DateTimeImmutable;
use think\exception\HttpException;

/** Pure normalized-bill comparison. Import provenance and complete snapshot ownership belong to the caller. */
final class FinanceBillReconciliation
{
    public const COLUMNS=['kind','local_ref','mer_id','channel','account_ref','account_version','currency',
        'amount','channel_fee','provider_no','occurred_at'];
    public const MAX_ROWS=1000;
    public const MAX_BYTES=1048576;

    public static function parse(string $csv):array
    {
        if(strlen($csv)>self::MAX_BYTES||str_contains($csv,"\0")||preg_match('//u',$csv)!==1)
            self::invalid('账单须为不超过 1 MiB 的 UTF-8 CSV');
        $digest=hash('sha256',$csv);
        if(str_starts_with($csv,"\xef\xbb\xbf"))$csv=substr($csv,3);
        $stream=fopen('php://memory','r+');
        if($stream===false)throw new \RuntimeException('Cannot open bill buffer');
        try{
            if(fwrite($stream,$csv)!==strlen($csv)||!rewind($stream))throw new \RuntimeException('Cannot read bill buffer');
            if(fgetcsv($stream,0,',','"','')!==self::COLUMNS)self::invalid('账单列名或顺序与标准模板不一致');
            $rows=[];
            while(($fields=fgetcsv($stream,0,',','"',''))!==false){
                if($fields===[null])continue;
                if(count($fields)!==count(self::COLUMNS)||count($rows)>=self::MAX_ROWS)
                    self::invalid('账单列数不正确或超过 1000 行');
                $rows[]=self::row(array_combine(self::COLUMNS,$fields),true);
            }
            return ['schema'=>1,'file_sha256'=>$digest,'rows'=>$rows];
        }finally{fclose($stream);}
    }

    /** Local rows must come from existing full payment/refund verification on one complete, bounded snapshot. */
    public static function reconcile(array $billRows,array $localRows):array
    {
        if(!array_is_list($billRows)||!array_is_list($localRows)
            ||count($billRows)>self::MAX_ROWS||count($localRows)>self::MAX_ROWS)self::invalid('对账记录范围不正确');
        $billRows=array_map(static fn(array $row):array=>self::row($row),$billRows);
        $localRows=array_map(static fn(array $row):array=>self::row($row),$localRows);
        $local=[];$occurrences=[];$seen=[];$results=[];
        foreach($localRows as $row){
            $key=self::key($row);
            if(isset($local[$key]))throw new \LogicException('Local financial movement reference is not unique');
            $local[$key]=$row;
        }
        foreach($billRows as $row){$key=self::key($row);$occurrences[$key]=($occurrences[$key]??0)+1;}
        foreach($billRows as $index=>$row){
            $key=self::key($row);$saved=$local[$key]??null;$seen[$key]=true;$differences=[];
            if($occurrences[$key]>1)$status='DUPLICATE_BILL_REFERENCE';
            elseif($saved===null)$status='MISSING_LOCAL';
            else{
                // A provider number missing locally is unresolved, even if the uploaded number is plausible.
                foreach(['mer_id','channel','account_ref','account_version','currency','amount','provider_no','occurred_at'] as $field)
                    if($row[$field]!==$saved[$field])$differences[]=$field;
                if($saved['provider_no']===null){
                    if(!in_array('provider_no',$differences,true))$differences[]='provider_no';
                    $status='LOCAL_PROVIDER_REFERENCE_PENDING';
                }else $status=$differences===[]?'MATCHED':'FIELD_DIFFERENCE';
            }
            $results[]=['bill_row'=>$index+1,'kind'=>$row['kind'],'local_ref'=>$row['local_ref'],
                'status'=>$status,'differences'=>$differences,'bill'=>$row,'local'=>$saved];
        }
        foreach($local as $key=>$row)if(!isset($seen[$key]))$results[]=['bill_row'=>null,'kind'=>$row['kind'],
            'local_ref'=>$row['local_ref'],'status'=>'MISSING_BILL','differences'=>[],'bill'=>null,'local'=>$row];
        $matched=count(array_filter($results,static fn(array $row):bool=>$row['status']==='MATCHED'));
        return ['schema'=>1,'scope'=>'normalized_bill_comparison_only','real_funds_enabled'=>false,
            'counts'=>['matched'=>$matched,'differences'=>count($results)-$matched],
            'totals'=>['bill'=>self::totals($billRows),'local'=>self::totals($localRows)],'rows'=>$results];
    }

    /** Input is the ordered projection of already verified applications, not raw observations or approval amounts. */
    public static function refundIncrements(array $applications):array
    {
        if(!array_is_list($applications)||count($applications)>32)self::invalid('退款应用序列不正确');
        $previous=0;$time=0;$actions=[];$out=[];
        foreach($applications as $application){
            if(!is_array($application))self::invalid('退款应用格式不正确');
            self::keys($application,['action_id','applied_at','confirmed_amount']);
            $id=$application['action_id'];
            if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1
                ||(strlen($id)===20&&strcmp($id,'18446744073709551615')>0)||isset($actions[$id]))
                self::invalid('退款应用引用不正确');
            $at=self::integer($application['applied_at'],4294967295,false);
            $amount=self::cents($application['confirmed_amount']);
            if($amount<$previous||$at<$time)self::invalid('退款应用顺序不正确');
            if($amount>$previous)$out[]=['action_id'=>$id,'applied_at'=>$at,'amount'=>self::amount($amount-$previous)];
            $previous=$amount;$time=$at;$actions[$id]=true;
        }
        return $out;
    }

    /** DECIMAL(12,2), checked before conversion; no binary floating point. */
    public static function cents(mixed $value,bool $signed=false):int
    {
        $sign=$signed?'-?':'';
        if(PHP_INT_SIZE<8||!is_string($value)||preg_match('/\A'.$sign.'(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)!==1
            ||$value==='-0.00')self::invalid('金额须为固定两位小数');
        return (int)str_replace('.','',$value);
    }

    public static function amount(int $cents):string
    {
        if($cents===PHP_INT_MIN)throw new \LogicException('Amount exceeds integer capacity');
        $absolute=abs($cents);
        return ($cents<0?'-':'').intdiv($absolute,100).'.'.str_pad((string)($absolute%100),2,'0',STR_PAD_LEFT);
    }

    private static function row(array $row,bool $csv=false):array
    {
        self::keys($row,self::COLUMNS);
        if(!in_array($row['kind'],['payment','refund'],true))self::invalid('账单类型须为 payment 或 refund');
        foreach(['local_ref','account_ref'] as $field)self::reference($row[$field]);
        if(!is_string($row['channel'])||preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D',$row['channel'])!==1)
            self::invalid('账单渠道格式不正确');
        $row['mer_id']=self::integer($row['mer_id'],4294967295,$csv);
        $row['account_version']=self::integer($row['account_version'],2147483647,$csv);
        if($row['currency']!=='CNY'||self::cents($row['amount'])<=0)self::invalid('账单金额须为正数且币种为 CNY');
        if($csv&&$row['channel_fee']==='')$row['channel_fee']=null;
        if($row['channel_fee']!==null)self::cents($row['channel_fee'],true);
        if($csv&&$row['provider_no']==='')$row['provider_no']=null;
        if($row['provider_no']!==null)self::reference($row['provider_no']);
        if($csv){
            $text=$row['occurred_at'];
            if(!is_string($text)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/D',$text)!==1)
                self::invalid('账单时间须包含明确时区');
            $normalized=str_ends_with($text,'Z')?substr($text,0,-1).'+00:00':$text;
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$normalized);
            if($date===false||$date->format('Y-m-d\TH:i:sP')!==$normalized)self::invalid('账单日期不正确');
            $row['occurred_at']=$date->getTimestamp();
        }
        $row['occurred_at']=self::integer($row['occurred_at'],4294967295,false);
        return $row;
    }

    private static function totals(array $rows):array
    {
        $totals=['payments'=>0,'refunds'=>0,'channel_fee'=>0];$feeKnown=true;
        foreach($rows as $row){
            $totals[$row['kind']==='payment'?'payments':'refunds']+=self::cents($row['amount']);
            if($row['channel_fee']===null)$feeKnown=false;else $totals['channel_fee']+=self::cents($row['channel_fee'],true);
        }
        $out=array_map(self::amount(...),$totals);
        if(!$feeKnown)$out['channel_fee']=null;
        return $out;
    }

    private static function key(array $row):string {return $row['kind'].'|'.$row['local_ref'];}
    private static function reference(mixed $value):void
    {if(!is_string($value)||preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',$value)!==1)self::invalid('账单流水或账户引用格式不正确');}
    private static function integer(mixed $value,int $maximum,bool $text):int
    {
        if($text&&is_string($value)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)===1)$value=(int)$value;
        if(!is_int($value)||$value<1||$value>$maximum)self::invalid('账单编号、版本或时间不正确');
        return $value;
    }
    private static function keys(array $value,array $expected):void
    {$actual=array_keys($value);sort($actual);sort($expected);if($actual!==$expected)self::invalid('账单记录字段不正确');}
    private static function invalid(string $message):never {throw new HttpException(422,$message);}
}
