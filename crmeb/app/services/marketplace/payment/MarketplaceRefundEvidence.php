<?php
declare(strict_types=1);
namespace app\services\marketplace\payment;

use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;

/** Exact saved testing refund facts. No driver call, transaction, inventory or real funds. */
final class MarketplaceRefundEvidence
{
    public const SCOPE='testing_refund_process_only';
    public static function canonical(array $value):string {return MarketplaceOrderInput::canonical($value);}
    public static function keys(array $value,array $keys):void
    {$a=array_keys($value);sort($a,SORT_STRING);sort($keys,SORT_STRING);if($a!==$keys)self::fail();}
    public static function id(mixed $value,int $maximum=2147483647):int
    {$v=MarketplaceOrderInput::unsigned($value,'记录编号',$maximum);if($v===0)self::fail();return $v;}
    public static function u64(mixed $value):string
    {
        if(is_int($value)&&$value>0)$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$value)!==1
            ||strlen($value)>20||(strlen($value)===20&&strcmp($value,'18446744073709551615')>0))self::fail();return $value;
    }
    public static function money(mixed $value,bool $positive=false):string
    {if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)!==1
        ||($positive&&bccomp($value,'0.00',2)<=0))self::fail();return $value;}
    public static function key(mixed $value):string
    {if(!is_string($value)||preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$value)!==1)self::fail();return $value;}
    public static function account(array $a):array
    {
        self::keys($a,['source','account_id','account_ref','version','mer_id','channel','currency','verification_scope']);
        if($a['source']!=='test_config'||$a['account_id']!==null||$a['channel']!=='test'||$a['currency']!=='CNY'
            ||$a['verification_scope']!=='test_driver_only'||!is_string($a['account_ref'])
            ||preg_match('/\Atest:[A-Za-z0-9][A-Za-z0-9_.:-]{0,122}\z/D',$a['account_ref'])!==1)self::fail();
        $a['version']=self::id($a['version']);$a['mer_id']=self::id($a['mer_id'],4294967295);return $a;
    }
    public static function domain(array $a):string
    {return hash('sha256',self::canonical(['source'=>$a['source'],'channel'=>$a['channel'],'account_ref'=>$a['account_ref']]),true);}
    public static function binding(array $b):array
    {
        self::keys($b,['refund_contract_version','refund_id','root_order_id','order_no','uid','mer_id','payment_attempt_id',
            'payment_attempt_ref','provider_trade_no','approval_action_id','amount','confirmed_before','requested_amount','currency','account_snapshot']);
        if(!in_array($b['refund_contract_version'],[1,2],true)||$b['currency']!=='CNY'||!is_string($b['order_no'])||$b['order_no']===''
            ||strlen($b['order_no'])>32||trim($b['order_no'])!==$b['order_no'])self::fail();
        foreach(['refund_id','root_order_id','uid'] as $k)$b[$k]=self::id($b[$k]);$b['mer_id']=self::id($b['mer_id'],4294967295);
        foreach(['payment_attempt_id','approval_action_id'] as $k)$b[$k]=self::u64($b[$k]);
        if(!is_string($b['payment_attempt_ref'])||preg_match('/\Atest-attempt:[a-f0-9]{32}\z/D',$b['payment_attempt_ref'])!==1
            ||!is_string($b['provider_trade_no'])||preg_match('/\Atest-payment:[a-f0-9]{32}\z/D',$b['provider_trade_no'])!==1)self::fail();
        $b['amount']=self::money($b['amount'],true);self::money($b['confirmed_before']);self::money($b['requested_amount'],true);
        if(bcadd($b['confirmed_before'],$b['amount'],2)!==$b['requested_amount'])self::fail();
        // Progress v2 starts a new controlled intent; it does not adopt an old PARTIAL history.
        if($b['refund_contract_version']===2&&$b['confirmed_before']!=='0.00')self::fail();
        $b['account_snapshot']=self::account($b['account_snapshot']);
        if($b['account_snapshot']['mer_id']!==$b['mer_id'])self::fail();return $b;
    }
    public static function initial(array $binding):array
    {
        $binding=self::binding($binding);
        $e=['schema'=>$binding['refund_contract_version'],'binding'=>$binding,'observations'=>[],'create'=>null,'success'=>null,'application'=>null];
        if($e['schema']===2)$e['applications']=[];return $e;
    }
    public static function result(array $r,int $version=1):array
    {
        if($version===2)return self::progressResult($r);
        if($version!==1)self::fail();
        self::keys($r,['state','scope','attempt_ref','binding_sha256','binding','provider_refund_no']);
        if($r['scope']!==self::SCOPE||!in_array($r['state'],['pending','succeeded','unknown'],true))self::fail();
        if($r['state']==='unknown'){
            foreach(['attempt_ref','binding_sha256','binding','provider_refund_no'] as $k)if($r[$k]!==null)self::fail();return $r;
        }
        if(!is_string($r['attempt_ref'])||preg_match('/\Atest-refund:[a-f0-9]{32}\z/D',$r['attempt_ref'])!==1
            ||!is_array($r['binding']))self::fail();$r['binding']=self::binding($r['binding']);
        if($r['binding']['refund_contract_version']!==1||$r['binding_sha256']!==hash('sha256',self::canonical($r['binding'])))self::fail();
        if($r['state']==='pending'&&$r['provider_refund_no']!==null)self::fail();
        if($r['state']==='succeeded'&&(!is_string($r['provider_refund_no'])
            ||preg_match('/\Atest-rfnd:[a-f0-9]{32}\z/D',$r['provider_refund_no'])!==1))self::fail();return $r;
    }
    public static function row(array $row):array
    {
        self::keys($row,['id','contract_version','refund_id','root_order_id','uid','mer_id','payment_attempt_id','approval_action_id',
            'idem_key','request_digest','binding_digest','account_domain_digest','account_snapshot','amount','currency',
            'attempt_ref','applied_refund_no','evidence','created_at','updated_at']);
        foreach(['id','payment_attempt_id','approval_action_id'] as $k)$row[$k]=self::u64($row[$k]);
        foreach(['refund_id','root_order_id','uid'] as $k)$row[$k]=self::id($row[$k]);
        $row['mer_id']=self::id($row['mer_id'],4294967295);$row['contract_version']=self::id($row['contract_version'],2);
        foreach(['created_at','updated_at'] as $k)$row[$k]=self::id($row[$k],4294967295);
        if($row['updated_at']<$row['created_at'])self::fail();self::key($row['idem_key']);self::money($row['amount'],true);
        foreach(['account_snapshot','evidence'] as $k){
            if(is_string($row[$k])){
                try{$row[$k]=self::jsonValue(json_decode($row[$k],false,64,JSON_THROW_ON_ERROR));}
                catch(\Throwable $error){self::fail();}
            }
            if(!is_array($row[$k]))self::fail();
        }
        $row['account_snapshot']=self::account($row['account_snapshot']);$e=$row['evidence'];
        if($row['contract_version']===2)return self::progressRow($row);
        self::keys($e,['schema','binding','observations','create','success','application']);
        if($e['schema']!==1||!is_array($e['binding'])||!is_array($e['observations'])||!array_is_list($e['observations'])
            ||count($e['observations'])>32)self::fail();$b=self::binding($e['binding']);
        if($b['refund_contract_version']!==1)self::fail();
        foreach(['refund_id','root_order_id','uid','mer_id','payment_attempt_id','approval_action_id','amount','currency','account_snapshot'] as $k)
            if(is_array($b[$k])?self::canonical($b[$k])!==self::canonical($row[$k]):$b[$k]!==$row[$k])self::fail();
        foreach(['request_digest','binding_digest','account_domain_digest'] as $k)if(!is_string($row[$k])||strlen($row[$k])!==32)self::fail();
        if(!hash_equals($row['binding_digest'],hash('sha256',self::canonical($b),true))
            ||!hash_equals($row['account_domain_digest'],self::domain($row['account_snapshot']))
            ||!hash_equals($row['request_digest'],hash('sha256',self::canonical(['binding'=>$b,'idempotency_key'=>$row['idem_key']]),true)))self::fail();
        $seen=[];$create=null;$createRef=null;$success=null;$successNumber=null;$successAt=null;$previousAt=$row['created_at'];
        foreach($e['observations'] as $fact){
            self::keys($fact,['key','op','result','at']);
            if(!in_array($fact['op'],['createRefund','queryRefund'],true))self::fail();self::id($fact['at'],4294967295);
            if($fact['at']<$previousAt||$fact['at']>$row['updated_at']||($create===null&&$fact['op']!=='createRefund'))self::fail();
            $previousAt=$fact['at'];
            $r=self::result($fact['result']);$key=hash('sha256',self::canonical(['op'=>$fact['op'],'result'=>$r]));
            if($fact['key']!==$key||isset($seen[$key]))self::fail();$seen[$key]=true;
            if($r['state']!=='unknown'&&(self::canonical($r['binding'])!==self::canonical($b)
                ||($row['attempt_ref']!==null&&$r['attempt_ref']!==$row['attempt_ref'])))self::fail();
            if($fact['op']==='createRefund'){
                if($create!==null)self::fail();$create=$key;$createRef=$r['attempt_ref'];if($r['state']!=='pending')self::fail();
            }
            if($r['state']==='succeeded'){
                if($fact['op']!=='queryRefund'||$create===null)self::fail();
                if($successNumber!==null&&$successNumber!==$r['provider_refund_no'])self::fail();
                if($success===null){$success=$key;$successNumber=$r['provider_refund_no'];$successAt=$fact['at'];}
            }
        }
        if($e['create']!==$create||$e['success']!==$success)self::fail();
        if($row['attempt_ref']!==$createRef)self::fail();
        if($e['application']===null){if($row['applied_refund_no']!==null)self::fail();}
        else{
            $a=$e['application'];self::keys($a,['fact','action_id','applied_at','refund_no']);
            if($success===null||!is_string($successNumber)||$a['fact']!==$success
                ||$a['refund_no']!==$successNumber||$row['applied_refund_no']!==$successNumber)self::fail();
            self::u64($a['action_id']);self::id($a['applied_at'],4294967295);
            if($a['applied_at']<$successAt||$a['applied_at']>$row['updated_at'])self::fail();
        }
        if(strlen(self::canonical($e))>65536)self::fail();return $row;
    }
    public static function observe(array $row,string $op,array $result,int $now):array
    {
        $row=self::row($row);
        if($row['contract_version']===2)return self::stageProgress($row,$op,$result,$now);
        $result=self::result($result);self::id($now,4294967295);
        if(!in_array($op,['createRefund','queryRefund'],true))self::fail();
        if($result['state']!=='unknown'&&self::canonical($result['binding'])!==self::canonical($row['evidence']['binding']))self::fail();
        $key=hash('sha256',self::canonical(['op'=>$op,'result'=>$result]));
        foreach($row['evidence']['observations'] as $fact)if($fact['key']===$key)return ['row'=>$row,'fact'=>$fact,'new'=>false];
        if(count($row['evidence']['observations'])>=32)self::fail();
        if($op==='createRefund'){
            if($row['evidence']['create']!==null||$result['state']!=='pending')self::fail();
            $row['attempt_ref']=$result['attempt_ref'];$row['evidence']['create']=$key;
        }elseif($row['attempt_ref']===null||($result['state']!=='unknown'&&$row['attempt_ref']!==$result['attempt_ref']))self::fail();
        $fact=['key'=>$key,'op'=>$op,'result'=>$result,'at'=>$now];$row['evidence']['observations'][]=$fact;
        if($result['state']==='succeeded'&&$row['evidence']['success']===null)$row['evidence']['success']=$key;
        $row['updated_at']=max($row['updated_at'],$now);return ['row'=>self::row($row),'fact'=>$fact,'new'=>true];
    }
    public static function state(array $row):string
    {
        $row=self::row($row);if($row['evidence']['application']!==null)return 'applied';
        if($row['evidence']['create']===null)return 'unresolved';
        $last=$row['evidence']['observations'][count($row['evidence']['observations'])-1];
        return $last['result']['state']==='pending'?'pending':'unresolved';
    }
    public static function descriptor(array $row):array
    {
        $row=self::row($row);foreach($row['evidence']['observations'] as $f)
            if($f['key']===$row['evidence']['create'])return $f['result'];self::fail();
    }
    /** Only known v2 results carry progress. A new driver's shared unknown result is never persisted as v2. */
    private static function progressResult(array $r):array
    {
        self::keys($r,['schema','state','scope','attempt_ref','binding_sha256','binding','provider_refund_no','confirmed_amount']);
        if($r['schema']!==2||$r['scope']!==self::SCOPE||!in_array($r['state'],['pending','succeeded'],true)
            ||!is_string($r['attempt_ref'])||preg_match('/\Atest-refund:[a-f0-9]{32}\z/D',$r['attempt_ref'])!==1
            ||!is_array($r['binding']))self::fail();
        $r['binding']=self::binding($r['binding']);$b=$r['binding'];
        if($b['refund_contract_version']!==2||$r['binding_sha256']!==hash('sha256',self::canonical($b)))self::fail();
        self::money($r['confirmed_amount']);
        if(bccomp($r['confirmed_amount'],$b['amount'],2)>0)self::fail();
        if($r['state']==='pending'){
            if($r['provider_refund_no']!==null||bccomp($r['confirmed_amount'],$b['amount'],2)>=0)self::fail();
        }elseif($r['confirmed_amount']!==$b['amount']||!is_string($r['provider_refund_no'])
            ||preg_match('/\Atest-rfnd:[a-f0-9]{32}\z/D',$r['provider_refund_no'])!==1)self::fail();
        return $r;
    }
    public static function progressState(array $result):array
    {
        $r=self::result($result,2);$confirmed=bcadd($r['binding']['confirmed_before'],$r['confirmed_amount'],2);
        return ['finance_state'=>$r['state']==='succeeded'?'SUCCEEDED':($r['confirmed_amount']==='0.00'?'PROCESSING':'PARTIAL'),
            'confirmed_amount'=>$confirmed];
    }
    /** row() has already normalized the common native columns; every committed progress fact is applied. */
    private static function progressRow(array $row):array
    {
        $e=$row['evidence'];self::keys($e,['schema','binding','observations','create','success','application','applications']);
        if($e['schema']!==2||!is_array($e['binding'])||!is_array($e['observations'])||!array_is_list($e['observations'])
            ||count($e['observations'])>32||!is_array($e['applications'])||!array_is_list($e['applications'])
            ||count($e['applications'])>32)self::fail();
        $b=self::binding($e['binding']);if($b['refund_contract_version']!==2)self::fail();
        foreach(['refund_id','root_order_id','uid','mer_id','payment_attempt_id','approval_action_id','amount','currency','account_snapshot'] as $k)
            if(is_array($b[$k])?self::canonical($b[$k])!==self::canonical($row[$k]):$b[$k]!==$row[$k])self::fail();
        foreach(['request_digest','binding_digest','account_domain_digest'] as $k)if(!is_string($row[$k])||strlen($row[$k])!==32)self::fail();
        if(!hash_equals($row['binding_digest'],hash('sha256',self::canonical($b),true))
            ||!hash_equals($row['account_domain_digest'],self::domain($row['account_snapshot']))
            ||!hash_equals($row['request_digest'],hash('sha256',self::canonical(['binding'=>$b,'idempotency_key'=>$row['idem_key']]),true)))self::fail();
        $seen=[];$actions=[];$create=null;$ref=null;$success=null;$final=null;$index=0;
        $previousAt=$row['created_at'];$previousApplied=$row['created_at'];$previous=null;$previousAmount='0.00';
        foreach($e['observations'] as $fact){
            self::keys($fact,['key','op','result','at']);
            if(!in_array($fact['op'],['createRefund','queryRefund'],true)||!is_int($fact['at'])
                ||$fact['at']<$previousAt||$fact['at']<$previousApplied||$fact['at']>$row['updated_at'])self::fail();
            self::id($fact['at'],4294967295);$previousAt=$fact['at'];$r=self::result($fact['result'],2);
            $key=hash('sha256',self::canonical(['op'=>$fact['op'],'result'=>$r]));
            if($fact['key']!==$key||isset($seen[$key])||self::canonical($r)!==self::canonical($fact['result'])
                ||self::canonical($r['binding'])!==self::canonical($b)||bccomp($r['confirmed_amount'],$previousAmount,2)<0)self::fail();
            $seen[$key]=true;$previousAmount=$r['confirmed_amount'];
            if($create===null){
                if($fact['op']!=='createRefund'||$r['state']!=='pending'||$r['confirmed_amount']!=='0.00')self::fail();
                $create=$key;$ref=$r['attempt_ref'];
            }elseif($fact['op']!=='queryRefund'||$r['attempt_ref']!==$ref)self::fail();
            if($success!==null)self::fail(); // A final known result is replayed, not appended again.
            $state=self::progressState($r);
            if($previous!==$state){
                $a=$e['applications'][$index++]??null;if(!is_array($a))self::fail();
                self::keys($a,['fact','action_id','applied_at','finance_state','confirmed_amount','refund_no']);
                $action=self::u64($a['action_id']);self::id($a['applied_at'],4294967295);
                if($a['fact']!==$key||$a['action_id']!==$action||isset($actions[$action])||!is_int($a['applied_at'])
                    ||$a['applied_at']<$fact['at']||$a['applied_at']>$row['updated_at']
                    ||$a['finance_state']!==$state['finance_state']||$a['confirmed_amount']!==$state['confirmed_amount']
                    ||$a['refund_no']!==$r['provider_refund_no'])self::fail();
                $actions[$action]=true;$previousApplied=$a['applied_at'];
                if($r['state']==='succeeded'){
                    $success=$key;$final=['fact'=>$key,'action_id'=>$action,'applied_at'=>$a['applied_at'],'refund_no'=>$a['refund_no']];
                }
            }
            $previous=$state;
        }
        if($e['application']!==null&&!is_array($e['application']))self::fail();
        if($index!==count($e['applications'])||$e['create']!==$create||$row['attempt_ref']!==$ref
            ||$e['success']!==$success||($final===null?$e['application']!==null:
                ($e['application']===null||self::canonical($e['application'])!==self::canonical($final)))
            ||$row['applied_refund_no']!==($final['refund_no']??null)
            ||strlen(self::canonical($e))>65536)self::fail();
        return $row;
    }
    /** In-memory proposal only. row()/DAO refuse it until its actual finance application is attached. */
    private static function stageProgress(array $row,string $op,array $result,int $now):array
    {
        $result=self::result($result,2);self::id($now,4294967295);
        if(!in_array($op,['createRefund','queryRefund'],true)||$now<$row['updated_at']
            ||self::canonical($result['binding'])!==self::canonical($row['evidence']['binding']))self::fail();
        $key=hash('sha256',self::canonical(['op'=>$op,'result'=>$result]));
        foreach($row['evidence']['observations'] as $fact)if($fact['key']===$key)return ['row'=>$row,'fact'=>$fact,'new'=>false];
        if(count($row['evidence']['observations'])>=32||$row['evidence']['success']!==null)self::fail();
        if($op==='createRefund'){
            if($row['evidence']['create']!==null||$result['state']!=='pending'||$result['confirmed_amount']!=='0.00')self::fail();
            $row['attempt_ref']=$result['attempt_ref'];$row['evidence']['create']=$key;
        }else{
            if($row['attempt_ref']===null||$row['attempt_ref']!==$result['attempt_ref'])self::fail();
            $observations=$row['evidence']['observations'];$last=$observations[count($observations)-1];
            if(bccomp($result['confirmed_amount'],$last['result']['confirmed_amount'],2)<0)self::fail();
        }
        $fact=['key'=>$key,'op'=>$op,'result'=>$result,'at'=>$now];$row['evidence']['observations'][]=$fact;
        if($result['state']==='succeeded')$row['evidence']['success']=$key;
        $row['updated_at']=$now;return ['row'=>$row,'fact'=>$fact,'new'=>true];
    }
    private static function jsonValue(mixed $value):mixed
    {
        if($value instanceof \stdClass){
            $fields=get_object_vars($value);if($fields===[])self::fail();
            foreach($fields as $key=>&$item){if(!is_string($key))self::fail();$item=self::jsonValue($item);}unset($item);
            ksort($fields,SORT_STRING);return $fields;
        }
        if(is_array($value)){
            if(!array_is_list($value))self::fail();foreach($value as &$item)$item=self::jsonValue($item);unset($item);return $value;
        }
        if(is_string($value)||is_int($value)||is_bool($value)||$value===null)return $value;self::fail();
    }
    private static function fail():never {throw new AuthUnavailableException();}
}
