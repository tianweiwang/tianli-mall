<?php
declare(strict_types=1);

namespace app\services\marketplace\payment;

use app\services\marketplace\order\MarketplaceOrderInput;

/** Pure testing-only evidence values. This class neither authenticates a driver call nor writes payment state. */
final class MarketplacePaymentEvidence
{
    private const U64_MAX='18446744073709551615';
    private const FACT_PREFIX="marketplace-payment-fact:v1\n";
    private const CODES=['BINDING_MISMATCH','CLOSED_THEN_SUCCESS','SUCCESS_REF_CHANGED','OTHER_WINNER',
        'ROOT_CANCELLED','RESERVE_RELEASED','MERCHANT_UNAVAILABLE','FULFILLMENT_DISABLED','ROOT_PAYMENT_MISMATCH'];

    public static function initial(): array
    {
        return ['schema'=>1,'observations'=>[],'first_closed'=>null,'first_succeeded'=>null,
            'application'=>null,'exceptions'=>[]];
    }

    /** Input rows are the complete, native-id-ordered, locked same-root collection, not a fact-key allowlist. */
    public static function encode(array $registry,array $attempts,string $attemptId): string
    {
        $context=self::context($registry,$attempts,$attemptId);
        return self::bytes($context['rows'][$context['target']]['evidence']);
    }

    /** Only the real service may supply the result of its actual internal adapter invocation. */
    public static function observe(array $registry,array $attempts,string $attemptId,string $op,array $result,int $receivedAt): array
    {
        $context=self::context($registry,$attempts,$attemptId);
        self::time($receivedAt);self::operation($op);self::result($result);
        $row=$context['rows'][$context['target']];$meta=$context['meta'][$context['target']];
        $fact=['op'=>$op,'attempt_id'=>$row['id'],'generation'=>$row['admitted_generation'],
            'account_sha256'=>$meta['account_sha256'],'result'=>$result];
        $factBytes=self::bytes($fact);$key=hash('sha256',self::FACT_PREFIX.$factBytes);
        $evidence=$row['evidence'];
        if(isset($context['facts']['f:'.$key])){
            $saved=$context['facts']['f:'.$key];
            if($saved['fact_bytes']!==$factBytes)self::collision();
            if($saved['row_key']!==$context['target'])self::reference();
            return $evidence;
        }
        $observation=['key'=>$key,'received_at'=>$receivedAt,'fact'=>$fact];
        self::limitBytes($observation,4096);
        if(count($evidence['observations'])>=8)self::capacity();
        $evidence['observations'][]=$observation;
        if(self::eligible($result,$row,$meta)){
            if($result['state']==='closed'&&$evidence['first_closed']===null)$evidence['first_closed']=$key;
            if($result['state']==='succeeded'&&$evidence['first_succeeded']===null)$evidence['first_succeeded']=$key;
        }
        return self::finish($context,$evidence);
    }

    /** Produces a value proposal from a retained query fact; real root/winner/business authorization remains outside. */
    public static function apply(array $registry,array $attempts,string $attemptId,string $factKey,int $at): array
    {
        $context=self::context($registry,$attempts,$attemptId);self::time($at);self::key($factKey);
        $row=$context['rows'][$context['target']];$evidence=$row['evidence'];
        $fact=self::localFact($context,$context['target'],$factKey);
        if(!$fact['eligible']||$fact['observation']['fact']['op']!=='queryPayment'
            ||$fact['observation']['fact']['result']['state']!=='succeeded')self::reference();
        $trade=$fact['observation']['fact']['result']['provider_trade_no'];
        if($evidence['first_succeeded']===null)self::reference();
        $first=self::localFact($context,$context['target'],$evidence['first_succeeded']);
        if($first['observation']['fact']['result']['provider_trade_no']!==$trade)self::reference();
        if($evidence['application']!==null){
            if($evidence['application']['root_payment']['trade_no']!==$trade)self::reference();
            return $evidence;
        }
        $evidence['application']=['fact'=>$factKey,'at'=>$at,'order_pk'=>$row['order_id'],'attempt_id'=>$row['id'],
            'root_payment'=>['paid'=>1,'pay_time'=>$at,'pay_type'=>'marketplace_test','trade_no'=>$trade]];
        // A local proposal only. The service must atomically save/read back this actual query reference and all root/winner fields.
        return self::finish($context,$evidence,$trade);
    }

    /** Business codes still require actual locked service facts; reference existence alone is never business authority. */
    public static function exception(array $registry,array $attempts,string $attemptId,string $code,string $factKey,?string $otherKey,int $firstAt): array
    {
        $context=self::context($registry,$attempts,$attemptId);self::time($firstAt);
        $entry=['code'=>$code,'fact'=>$factKey,'other'=>$otherKey,'first_at'=>$firstAt];
        self::exceptionValue($entry,$context,$context['target']);
        $evidence=$context['rows'][$context['target']]['evidence'];
        $identity=self::bytes(['code'=>$code,'fact'=>$factKey,'other'=>$otherKey]);
        foreach($evidence['exceptions'] as $saved){
            if(self::bytes(['code'=>$saved['code'],'fact'=>$saved['fact'],'other'=>$saved['other']])===$identity)return $evidence;
        }
        if(count($evidence['exceptions'])>=72)self::capacity();
        self::limitBytes($entry,256);$evidence['exceptions'][]=$entry;
        return self::finish($context,$evidence);
    }

    private static function context(array $registry,array $attempts,string $attemptId): array
    {
        self::object($registry,['order_id','uid','mer_id','payment_contract_version','attempt_generation']);
        self::integer($registry['order_id'],2147483647);self::integer($registry['uid'],2147483647);
        self::integer($registry['mer_id'],4294967295);
        if($registry['payment_contract_version']!==1)self::schema();
        self::u64($registry['attempt_generation'],true);self::u64($attemptId,false);self::listing($attempts);
        $context=['registry'=>$registry,'rows'=>[],'meta'=>[],'facts'=>[],'target'=>'a:'.$attemptId];
        $previous=null;$generations=[];$idempotencies=[];$rootBinding=null;
        foreach($attempts as $row){
            if(!is_array($row))self::schema();
            self::object($row,['id','order_id','uid','mer_id','payment_contract_version','admitted_generation','order_no',
                'idem_key','amount','currency','account_snapshot','request_digest','binding_digest','account_domain_digest',
                'created_at','attempt_ref','applied_trade_no','evidence']);
            self::u64($row['id'],false);self::u64($row['admitted_generation'],false);
            if($previous!==null&&bccomp($row['id'],$previous,0)<=0)self::schema();
            $previous=$row['id'];
            foreach(['order_id','uid','mer_id','payment_contract_version'] as $field){
                if($row[$field]!==$registry[$field])self::schema();
            }
            self::time($row['created_at']);self::orderNo($row['order_no']);self::idempotency($row['idem_key']);
            self::amount($row['amount']);
            if($row['currency']!=='CNY'||!is_array($row['account_snapshot'])||!is_array($row['evidence']))self::schema();
            if(isset($idempotencies['i:'.$row['idem_key']]))self::schema();
            $idempotencies['i:'.$row['idem_key']]=true;
            $savedRootBinding=[$row['order_no'],$row['amount'],$row['currency']];
            if($rootBinding!==null&&$savedRootBinding!==$rootBinding)self::schema();
            $rootBinding=$savedRootBinding;
            self::account($row['account_snapshot']);
            if($row['account_snapshot']['mer_id']!==$row['mer_id'])self::schema();
            $account=$row['account_snapshot'];
            $binding=['order_pk'=>$row['order_id'],'order_no'=>$row['order_no'],'uid'=>$row['uid'],'mer_id'=>$row['mer_id'],
                'amount'=>$row['amount'],'currency'=>$row['currency'],'channel'=>$account['channel'],
                'account_source'=>$account['source'],'account_id'=>$account['account_id'],'account_ref'=>$account['account_ref'],
                'account_version'=>$account['version']];
            self::binding($binding);$bindingBytes=self::bytes($binding);
            $request=['binding'=>$binding,'idempotency_key'=>$row['idem_key']];
            $domain=['source'=>$account['source'],'channel'=>$account['channel'],'account_ref'=>$account['account_ref']];
            foreach(['request_digest'=>self::bytes($request),'binding_digest'=>$bindingBytes,
                'account_domain_digest'=>self::bytes($domain)] as $field=>$bytes){
                if(!is_string($row[$field])||strlen($row[$field])!==32
                    ||!hash_equals(hash('sha256',$bytes,true),$row[$field]))self::schema();
            }
            if($row['attempt_ref']!==null)self::attemptReference($row['attempt_ref']);
            if($row['applied_trade_no']!==null)self::tradeReference($row['applied_trade_no']);
            $rowKey='a:'.$row['id'];
            if(isset($context['rows'][$rowKey]))self::schema();
            $context['rows'][$rowKey]=$row;
            $context['meta'][$rowKey]=['binding_bytes'=>$bindingBytes,'binding_sha256'=>bin2hex($row['binding_digest']),
                'account_sha256'=>hash('sha256',self::bytes($account))];
            $generations[]=$row['admitted_generation'];
        }
        usort($generations,static fn(string $left,string $right):int=>bccomp($left,$right,0));
        $generation='0';
        foreach($generations as $savedGeneration){
            $generation=bcadd($generation,'1',0);
            if($savedGeneration!==$generation)self::schema();
        }
        if($generation!==$registry['attempt_generation']||!isset($context['rows'][$context['target']]))self::schema();
        // Two passes allow only complete same-root sibling observations to resolve related references.
        foreach($context['rows'] as $rowKey=>$row){
            $evidence=$row['evidence'];self::envelope($evidence);
            foreach($evidence['observations'] as $position=>$observation){
                if(!is_array($observation))self::schema();
                self::object($observation,['key','received_at','fact']);self::key($observation['key']);self::time($observation['received_at']);
                $fact=$observation['fact'];
                if(!is_array($fact))self::schema();
                self::object($fact,['op','attempt_id','generation','account_sha256','result']);
                self::operation($fact['op']);self::u64($fact['attempt_id'],false);self::u64($fact['generation'],false);
                self::key($fact['account_sha256']);
                if($fact['attempt_id']!==$row['id']||$fact['generation']!==$row['admitted_generation']
                    ||$fact['account_sha256']!==$context['meta'][$rowKey]['account_sha256']||!is_array($fact['result']))self::schema();
                self::result($fact['result']);$factBytes=self::bytes($fact);
                if(!hash_equals(hash('sha256',self::FACT_PREFIX.$factBytes),$observation['key']))self::schema();
                self::limitBytes($observation,4096);$factKey='f:'.$observation['key'];
                if(isset($context['facts'][$factKey])){
                    if($context['facts'][$factKey]['fact_bytes']!==$factBytes)self::collision();
                    self::schema(); // Duplicate saved items are not a second observation.
                }
                $context['facts'][$factKey]=['observation'=>$observation,'fact_bytes'=>$factBytes,'row_key'=>$rowKey,
                    'position'=>$position,'eligible'=>self::eligible($fact['result'],$row,$context['meta'][$rowKey])];
            }
        }
        $applications=0;
        foreach($context['rows'] as $rowKey=>$row){
            self::savedEvidence($context,$rowKey);
            if($row['evidence']['application']!==null&&++$applications>1)self::reference();
        }
        return $context;
    }

    private static function envelope(array $evidence): void
    {
        self::object($evidence,['schema','observations','first_closed','first_succeeded','application','exceptions']);
        if($evidence['schema']!==1)self::schema();
        self::listing($evidence['observations']);self::listing($evidence['exceptions']);
        if(count($evidence['observations'])>8||count($evidence['exceptions'])>72)self::capacity();
        foreach(['first_closed','first_succeeded'] as $field)if($evidence[$field]!==null)self::key($evidence[$field]);
        if($evidence['application']!==null&&!is_array($evidence['application']))self::schema();
    }

    private static function savedEvidence(array $context,string $rowKey): void
    {
        $row=$context['rows'][$rowKey];$evidence=$row['evidence'];$firstClosed=null;$firstSucceeded=null;
        foreach($evidence['observations'] as $observation){
            $fact=self::localFact($context,$rowKey,$observation['key']);
            if(!$fact['eligible'])continue;
            $state=$observation['fact']['result']['state'];
            if($state==='closed'&&$firstClosed===null)$firstClosed=$observation['key'];
            if($state==='succeeded'&&$firstSucceeded===null)$firstSucceeded=$observation['key'];
        }
        if($evidence['first_closed']!==$firstClosed||$evidence['first_succeeded']!==$firstSucceeded)self::reference();
        $application=$evidence['application'];
        if($application===null){
            if($row['applied_trade_no']!==null)self::reference();
        }else{
            self::object($application,['fact','at','order_pk','attempt_id','root_payment']);
            self::key($application['fact']);self::time($application['at']);
            self::integer($application['order_pk'],2147483647);self::u64($application['attempt_id'],false);
            if($application['order_pk']!==$row['order_id']||$application['attempt_id']!==$row['id']
                ||!is_array($application['root_payment']))self::reference();
            $payment=$application['root_payment'];self::object($payment,['paid','pay_time','pay_type','trade_no']);
            self::time($payment['pay_time']);self::tradeReference($payment['trade_no']);
            if($payment['paid']!==1||$payment['pay_time']!==$application['at']||$payment['pay_type']!=='marketplace_test')self::reference();
            $fact=self::localFact($context,$rowKey,$application['fact']);
            if(!$fact['eligible']||$fact['observation']['fact']['op']!=='queryPayment'
                ||$fact['observation']['fact']['result']['state']!=='succeeded'||$firstSucceeded===null)self::reference();
            $first=self::localFact($context,$rowKey,$firstSucceeded);
            if($payment['trade_no']!==$fact['observation']['fact']['result']['provider_trade_no']
                ||$payment['trade_no']!==$first['observation']['fact']['result']['provider_trade_no']
                ||$row['applied_trade_no']!==$payment['trade_no'])self::reference();
            self::limitBytes($application,4096);
        }
        $seen=[];
        foreach($evidence['exceptions'] as $entry){
            if(!is_array($entry))self::schema();
            self::exceptionValue($entry,$context,$rowKey);self::limitBytes($entry,256);
            $identity=self::bytes(['code'=>$entry['code'],'fact'=>$entry['fact'],'other'=>$entry['other']]);
            if(isset($seen[$identity]))self::schema();$seen[$identity]=true;
        }
        self::limitBytes($evidence,65535);
    }

    private static function exceptionValue(array $entry,array $context,string $rowKey): void
    {
        self::object($entry,['code','fact','other','first_at']);
        if(!is_string($entry['code'])||!in_array($entry['code'],self::CODES,true))self::schema();
        self::key($entry['fact']);self::time($entry['first_at']);
        $fact=self::localFact($context,$rowKey,$entry['fact']);$other=null;
        if($entry['other']!==null){
            self::key($entry['other']);$other=$context['facts']['f:'.$entry['other']]??null;
            if($other===null||$entry['other']===$entry['fact'])self::reference();
            if($other['row_key']!==$rowKey&&!$other['eligible'])self::reference();
        }
        $result=$fact['observation']['fact']['result'];$code=$entry['code'];
        if($code==='BINDING_MISMATCH'){
            $row=$context['rows'][$rowKey];$meta=$context['meta'][$rowKey];
            if($result['state']==='unknown'||(self::bytes($result['binding'])===$meta['binding_bytes']
                &&$result['binding_sha256']===$meta['binding_sha256']
                &&($row['attempt_ref']===null||$result['attempt_ref']===$row['attempt_ref'])))self::reference();
            return;
        }
        if($code==='SUCCESS_REF_CHANGED'){
            $firstKey=$context['rows'][$rowKey]['evidence']['first_succeeded'];
            if($result['state']!=='succeeded'||$other===null||$other['row_key']!==$rowKey||!$other['eligible']
                ||$entry['other']!==$firstKey||$other['observation']['fact']['result']['state']!=='succeeded'
                ||$other['position']>=$fact['position']
                ||self::bytes($result['binding'])!==$context['meta'][$rowKey]['binding_bytes']
                ||$result['provider_trade_no']===$other['observation']['fact']['result']['provider_trade_no'])self::reference();
            return;
        }
        // Unknown or a mismatched binding cannot prove these successful-payment business contradictions.
        if(!$fact['eligible']||$result['state']!=='succeeded')self::reference();
        if($other!==null&&!$other['eligible'])self::reference();
        if($code==='CLOSED_THEN_SUCCESS'){
            if($other===null||$other['row_key']!==$rowKey||$entry['other']!==$context['rows'][$rowKey]['evidence']['first_closed']
                ||$other['observation']['fact']['result']['state']!=='closed'||$other['position']>=$fact['position'])self::reference();
        }elseif($code==='OTHER_WINNER'){
            if($other===null||$other['row_key']===$rowKey||$other['observation']['fact']['result']['state']!=='succeeded')self::reference();
            $application=$context['rows'][$other['row_key']]['evidence']['application'];
            if(!is_array($application)||($application['fact']??null)!==$entry['other'])self::reference();
        }
    }

    private static function finish(array $context,array $evidence,?string $candidateAppliedTrade=null): array
    {
        $rows=$context['rows'];$rows[$context['target']]['evidence']=$evidence;
        if($candidateAppliedTrade!==null)$rows[$context['target']]['applied_trade_no']=$candidateAppliedTrade;
        $checked=self::context($context['registry'],array_values($rows),$rows[$context['target']]['id']);
        return $checked['rows'][$checked['target']]['evidence'];
    }

    private static function localFact(array $context,string $rowKey,string $key): array
    {
        $fact=$context['facts']['f:'.$key]??null;
        if($fact===null||$fact['row_key']!==$rowKey)self::reference();
        return $fact;
    }

    private static function eligible(array $result,array $row,array $meta): bool
    {
        return $result['state']!=='unknown'&&$row['attempt_ref']!==null&&$result['attempt_ref']===$row['attempt_ref']
            &&$result['binding_sha256']===$meta['binding_sha256']&&self::bytes($result['binding'])===$meta['binding_bytes'];
    }

    private static function account(array $account): void
    {
        self::object($account,['source','account_id','account_ref','version','mer_id','channel','currency','verification_scope']);
        if($account['source']!=='test_config'||$account['account_id']!==null||$account['channel']!=='test'
            ||$account['currency']!=='CNY'||$account['verification_scope']!=='test_driver_only')self::schema();
        self::accountReference($account['account_ref']);self::integer($account['version'],2147483647);self::integer($account['mer_id'],4294967295);
    }

    private static function binding(array $binding): void
    {
        self::object($binding,['order_pk','order_no','uid','mer_id','amount','currency','channel','account_source','account_id','account_ref','account_version']);
        self::integer($binding['order_pk'],2147483647);self::integer($binding['uid'],2147483647);self::integer($binding['mer_id'],4294967295);
        self::orderNo($binding['order_no']);self::amount($binding['amount']);self::accountReference($binding['account_ref']);
        self::integer($binding['account_version'],2147483647);
        if($binding['currency']!=='CNY'||$binding['channel']!=='test'||$binding['account_source']!=='test_config'||$binding['account_id']!==null)self::schema();
    }

    private static function result(array $result): void
    {
        self::object($result,['state','scope','attempt_ref','binding_sha256','binding','provider_trade_no']);
        if($result['scope']!=='testing_process_only'||!is_string($result['state'])
            ||!in_array($result['state'],['pending','closed','succeeded','unknown'],true))self::schema();
        if($result['state']==='unknown'){
            foreach(['attempt_ref','binding_sha256','binding','provider_trade_no'] as $field)if($result[$field]!==null)self::schema();
            return;
        }
        self::attemptReference($result['attempt_ref']);self::key($result['binding_sha256']);
        if(!is_array($result['binding']))self::schema();self::binding($result['binding']);
        if(!hash_equals(hash('sha256',self::bytes($result['binding'])),$result['binding_sha256']))self::schema();
        if($result['state']==='succeeded'){
            self::tradeReference($result['provider_trade_no']);
            if(substr($result['attempt_ref'],13)!==substr($result['provider_trade_no'],13))self::schema();
        }elseif($result['provider_trade_no']!==null)self::schema();
    }

    private static function object(array $value,array $fields): void
    {
        $actual=array_keys($value);sort($actual,SORT_STRING);sort($fields,SORT_STRING);
        if($actual!==$fields)self::schema();
    }

    private static function listing(mixed $value): void
    {
        if(!is_array($value)||!array_is_list($value))self::schema();
    }

    private static function integer(mixed $value,int $maximum): void
    {
        if(!is_int($value)||$value<1||$value>$maximum)self::schema();
    }

    private static function time(mixed $value): void
    {
        self::integer($value,4294967295);
    }

    private static function u64(mixed $value,bool $allowZero): void
    {
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1
            ||strlen($value)>20||bccomp($value,self::U64_MAX,0)>0||(!$allowZero&&$value==='0'))self::schema();
    }

    private static function key(mixed $value): void
    {
        if(!is_string($value)||preg_match('/\A[0-9a-f]{64}\z/D',$value)!==1)self::schema();
    }

    private static function operation(mixed $value): void
    {
        if(!is_string($value)||!in_array($value,['createPayment','queryPayment','closePayment'],true))self::schema();
    }

    private static function orderNo(mixed $value): void
    {
        if(!is_string($value)||$value===''||strlen($value)>32||trim($value)!==$value
            ||preg_match('//u',$value)!==1||str_contains($value,"\0"))self::schema();
    }

    private static function idempotency(mixed $value): void
    {
        if(!is_string($value)||strlen($value)>128||preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D',$value)!==1)self::schema();
    }

    private static function amount(mixed $value): void
    {
        if(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$value)!==1
            ||bccomp($value,'0.00',2)<=0)self::schema();
    }

    private static function accountReference(mixed $value): void
    {
        if(!is_string($value)||strlen($value)>128||preg_match('/\Atest:[A-Za-z0-9][A-Za-z0-9_.:-]*\z/D',$value)!==1)self::schema();
    }

    private static function attemptReference(mixed $value): void
    {
        if(!is_string($value)||preg_match('/\Atest-attempt:[0-9a-f]{32}\z/D',$value)!==1)self::schema();
    }

    private static function tradeReference(mixed $value): void
    {
        if(!is_string($value)||preg_match('/\Atest-payment:[0-9a-f]{32}\z/D',$value)!==1)self::schema();
    }

    private static function bytes(array $value): string
    {
        try{return MarketplaceOrderInput::canonical($value);}
        catch(\Throwable $error){throw new \LengthException('EVIDENCE_CAPACITY',0,$error);}
    }

    private static function limitBytes(array $value,int $maximum): void
    {
        if(strlen(self::bytes($value))>$maximum)self::capacity();
    }

    private static function schema(): never
    {
        throw new \DomainException('MS5_PAYMENT_EVIDENCE_SCHEMA');
    }

    private static function reference(): never
    {
        throw new \DomainException('MS5_PAYMENT_EVIDENCE_REFERENCE');
    }

    private static function capacity(): never
    {
        throw new \LengthException('EVIDENCE_CAPACITY');
    }

    private static function collision(): never
    {
        throw new \DomainException('FACT_KEY_COLLISION');
    }
}
