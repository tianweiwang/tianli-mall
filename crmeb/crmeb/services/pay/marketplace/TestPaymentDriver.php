<?php
declare(strict_types=1);

namespace crmeb\services\pay\marketplace;

use app\services\marketplace\order\MarketplaceOrderInput;
use app\services\marketplace\payment\MarketplacePaymentServices;
use crmeb\exceptions\AuthUnavailableException;

/** Only this instance knows its attempts. No database/cache persistence, SDK or paid write; refunds retain the same process-only authority. */
final class TestPaymentDriver implements PaymentAdapter
{
    private const SCOPE='testing_process_only';
    private array $attempts=[];
    private array $refundAttempts=[];
    private array $refundByKey=[];
    private array $refundProgressPlans=[];
    private array $byOrder=[];
    private array $byIdempotency=[];
    private readonly \think\App $app;

    public function __construct(private PaymentAccountResolver $accounts)
    {
        PaymentAccount::assertTesting();
        $this->app=app();
    }

    public function createPayment(array $actualOrder, PaymentAccount $account, string $idempotencyKey): array
    {
        $this->assertScope();
        $this->app->make(MarketplacePaymentServices::class)->consumeCreateContext($this,$actualOrder,$account,$idempotencyKey);
        if(strlen($idempotencyKey)>128||preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D',$idempotencyKey)!==1)
            throw new AuthUnavailableException();
        $binding=self::binding($actualOrder,$account);$slot=$binding['order_pk']."\0".$idempotencyKey;
        $digest=hash('sha256',MarketplaceOrderInput::canonical(['binding'=>$binding,'idempotency_key'=>$idempotencyKey]));
        // An original attempt remains bound to its original account even after it has been closed.
        if(isset($this->byIdempotency[$slot])){
            $reference=$this->byIdempotency[$slot];$saved=$this->attempts[$reference];
            if($saved['request_digest']!==$digest||$saved['binding']!==$binding)throw new AuthUnavailableException();
            return self::result($reference,$saved);
        }
        $resolved=$this->accounts->forOrder($actualOrder);
        if($resolved->snapshot()!==$account->snapshot()
            ||MarketplaceOrderInput::unsigned($actualOrder['paid']??null,'支付状态',1)!==0
            ||MarketplaceOrderInput::unsigned($actualOrder['is_cancel']??null,'取消状态',1)!==0
            ||MarketplaceOrderInput::unsigned($actualOrder['status']??null,'订单状态',255)!==0
            ||bccomp($binding['amount'],'0.00',2)<=0)throw new AuthUnavailableException();
        do{$reference='test-attempt:'.bin2hex(random_bytes(16));}while(isset($this->attempts[$reference]));
        $saved=['state'=>'pending','binding'=>$binding,
            'binding_sha256'=>hash('sha256',MarketplaceOrderInput::canonical($binding)),
            'idempotency_key'=>$idempotencyKey,'request_digest'=>$digest,'provider_trade_no'=>null];
        $this->attempts[$reference]=$saved;$this->byIdempotency[$slot]=$reference;
        $this->byOrder[$binding['order_pk']][]=$reference;
        return self::result($reference,$saved);
    }

    /** This private map is the only state authority; supplied descriptor status is ignored. */
    public function queryPayment(array $paymentAttempt): array
    {
        $this->assertScope();$reference=$this->knownReference($paymentAttempt);
        return $reference===null?self::unknown():self::result($reference,$this->attempts[$reference]);
    }

    public function closePayment(array $paymentAttempt): array
    {
        $this->assertScope();$reference=$this->knownReference($paymentAttempt);
        if($reference===null)return self::unknown();
        if($this->attempts[$reference]['state']==='pending')$this->attempts[$reference]['state']='closed';
        return self::result($reference,$this->attempts[$reference]);
    }

    /** Internal testing control only; this reference never represents an institution transaction. */
    public function completeTestPayment(array $originalAttempt): array
    {
        $this->assertScope();$reference=$this->knownReference($originalAttempt);
        if($reference===null)return self::unknown();
        if($this->attempts[$reference]['state']==='pending'){
            $this->attempts[$reference]['provider_trade_no']='test-payment:'.substr($reference,strlen('test-attempt:'));
            $this->attempts[$reference]['state']='succeeded';
        }
        return self::result($reference,$this->attempts[$reference]);
    }

    /**
     * Internal test locator, not a seventh channel/payment method or durable attempt repository.
     * Empty/new-instance/mismatched evidence is unknown, never vacuously all closed.
     * @return array{state:'known'|'unknown',scope:string,attempts:list<array>}
     */
    public function attemptsForOrder(array $actualOrder): array
    {
        $this->assertScope();
        try{$identity=self::orderBinding($actualOrder);}catch(\Throwable $error){return self::unknownOrder();}
        $references=$this->byOrder[$identity['order_pk']]??[];
        if(!$references)return self::unknownOrder();$attempts=[];
        foreach($references as $reference){
            $saved=$this->attempts[$reference]??null;
            if(!is_array($saved)||array_intersect_key($saved['binding'],$identity)!==$identity)return self::unknownOrder();
            $attempts[]=self::result($reference,$saved);
        }
        return ['state'=>'known','scope'=>self::SCOPE,'attempts'=>$attempts];
    }

    /** Internal normal-test control only: no route/configuration can register or reconstruct a plan. */
    public function planTestRefundProgress(int $refundId,string $idempotencyKey,array $amounts):void
    {
        $this->assertScope();\app\services\marketplace\payment\MarketplaceRefundEvidence::id($refundId);
        \app\services\marketplace\payment\MarketplaceRefundEvidence::key($idempotencyKey);
        if(!array_is_list($amounts)||count($amounts)<2||count($amounts)>30)throw new AuthUnavailableException();
        $previous='0.00';foreach($amounts as $amount){
            \app\services\marketplace\payment\MarketplaceRefundEvidence::money($amount,true);
            if(bccomp($amount,$previous,2)<=0)throw new AuthUnavailableException();$previous=$amount;
        }
        $slot=$refundId."\0".$idempotencyKey;
        if(isset($this->refundByKey[$slot])||isset($this->refundProgressPlans[$slot]))throw new AuthUnavailableException();
        $this->refundProgressPlans[$slot]=$amounts;
    }
    public function refundContractVersion(int $refundId,string $idempotencyKey):int
    {
        $this->assertScope();\app\services\marketplace\payment\MarketplaceRefundEvidence::id($refundId);
        \app\services\marketplace\payment\MarketplaceRefundEvidence::key($idempotencyKey);
        return isset($this->refundProgressPlans[$refundId."\0".$idempotencyKey])?2:1;
    }
    public function createRefund(array $originalPaymentAttempt, array $actualRefund, string $idempotencyKey): array
    {
        $this->assertScope();
        $this->app->make(\app\services\marketplace\payment\MarketplaceRefundServices::class)
            ->consumeCreateContext($this,$originalPaymentAttempt,$actualRefund,$idempotencyKey);
        \app\services\marketplace\payment\MarketplaceRefundEvidence::key($idempotencyKey);
        $binding=\app\services\marketplace\payment\MarketplaceRefundEvidence::binding($actualRefund['binding']);
        $slot=$binding['refund_id']."\0".$idempotencyKey;
        if(isset($this->refundByKey[$slot])){
            $ref=$this->refundByKey[$slot];
            if($this->refundAttempts[$ref]['binding']!==$binding)throw new AuthUnavailableException();
            return $this->refundResult($ref);
        }
        $progress=[];
        if($binding['refund_contract_version']===2){
            $plan=$this->refundProgressPlans[$slot]??null;
            if(!is_array($plan)||$plan[count($plan)-1]!==$binding['amount'])throw new AuthUnavailableException();
            $progress=['confirmed_amount'=>'0.00','progress'=>$plan,'next'=>0];
        }elseif(isset($this->refundProgressPlans[$slot]))throw new AuthUnavailableException();
        do{$ref='test-refund:'.bin2hex(random_bytes(16));}while(isset($this->refundAttempts[$ref]));
        $this->refundAttempts[$ref]=['state'=>'pending','binding'=>$binding,'provider_refund_no'=>null]+$progress;
        $this->refundByKey[$slot]=$ref;return $this->refundResult($ref);
    }

    public function queryRefund(array $refundAttempt): array
    {
        $this->assertScope();$ref=$this->knownRefund($refundAttempt);
        return $ref===null?self::unknownRefund():$this->refundResult($ref);
    }


    /** Internal testing control. It only completes this exact instance's known refund descriptor. */
    public function completeTestRefund(array $original): array
    {
        $this->assertScope();$ref=$this->knownRefund($original);
        if($ref===null)return self::unknownRefund();
        if($this->refundAttempts[$ref]['binding']['refund_contract_version']===2)return $this->advanceTestRefund($original);
        if($this->refundAttempts[$ref]['state']==='pending'){
            $this->refundAttempts[$ref]['state']='succeeded';
            $this->refundAttempts[$ref]['provider_refund_no']='test-rfnd:'.substr($ref,strlen('test-refund:'));
        }
        return $this->refundResult($ref);
    }

    /** Advance only this instance's original descriptor; queryRefund itself never advances a plan. */
    public function advanceTestRefund(array $original):array
    {
        $this->assertScope();$ref=$this->knownRefund($original);
        if($ref===null)return self::unknownRefund();$saved=$this->refundAttempts[$ref];
        if($saved['binding']['refund_contract_version']!==2)throw new AuthUnavailableException();
        if($saved['state']==='pending'){
            $amount=$saved['progress'][$saved['next']]??null;
            if(!is_string($amount)||bccomp($amount,$saved['confirmed_amount'],2)<=0)throw new AuthUnavailableException();
            $saved['confirmed_amount']=$amount;$saved['next']++;
            if($amount===$saved['binding']['amount']){
                $saved['state']='succeeded';$saved['provider_refund_no']='test-rfnd:'.substr($ref,strlen('test-refund:'));
            }
            $this->refundAttempts[$ref]=$saved;
        }
        return $this->refundResult($ref);
    }

    private function knownRefund(array $descriptor):?string
    {
        $ref=$descriptor['attempt_ref']??null;
        if(($descriptor['scope']??null)!==\app\services\marketplace\payment\MarketplaceRefundEvidence::SCOPE
            ||!is_string($ref)||!isset($this->refundAttempts[$ref]))return null;
        $saved=$this->refundAttempts[$ref];
        if(($descriptor['binding_sha256']??null)!==hash('sha256',MarketplaceOrderInput::canonical($saved['binding']))
            ||($descriptor['binding']??null)!==$saved['binding'])return null;return $ref;
    }
    private function refundResult(string $ref):array
    {
        $saved=$this->refundAttempts[$ref];
        $result=['state'=>$saved['state'],'scope'=>\app\services\marketplace\payment\MarketplaceRefundEvidence::SCOPE,
            'attempt_ref'=>$ref,'binding_sha256'=>hash('sha256',MarketplaceOrderInput::canonical($saved['binding'])),
            'binding'=>$saved['binding'],'provider_refund_no'=>$saved['provider_refund_no']];
        if($saved['binding']['refund_contract_version']===2)$result+=['schema'=>2,'confirmed_amount'=>$saved['confirmed_amount']];
        return $result;
    }
    private static function unknownRefund():array
    {
        return ['state'=>'unknown','scope'=>\app\services\marketplace\payment\MarketplaceRefundEvidence::SCOPE,
            'attempt_ref'=>null,'binding_sha256'=>null,'binding'=>null,'provider_refund_no'=>null];
    }

    public function verifyAndParseNotification(array $rawRequest): array
    {
        $this->assertScope();throw new \LogicException('MARKETPLACE_TEST_NOTIFICATION_UNSUPPORTED');
    }

    private static function orderBinding(array $order): array
    {
        $identity=[];
        foreach(['id'=>['order_pk',2147483647],'uid'=>['uid',2147483647],'mer_id'=>['mer_id',4294967295]] as $field=>[$target,$maximum]){
            $identity[$target]=MarketplaceOrderInput::unsigned($order[$field]??null,'订单身份',$maximum);
            if($identity[$target]===0)throw new AuthUnavailableException();
        }
        if(MarketplaceOrderInput::unsigned($order['marketplace_trade_version']??null,'保存版本',1)!==1
            ||!is_string($order['order_id']??null)||$order['order_id']===''
            ||strlen($order['order_id'])>32||trim($order['order_id'])!==$order['order_id']
            ||!is_string($order['pay_price']??null)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$order['pay_price'])!==1)
            throw new AuthUnavailableException();
        return ['order_pk'=>$identity['order_pk'],'order_no'=>$order['order_id'],'uid'=>$identity['uid'],
            'mer_id'=>$identity['mer_id'],'amount'=>$order['pay_price']];
    }

    private static function binding(array $order, PaymentAccount $account): array
    {
        $identity=self::orderBinding($order);
        if($account->source!=='test_config'||$account->account_id!==null||$account->channel!=='test'
            ||$account->verification_scope!=='test_driver_only'||$account->currency!=='CNY'
            ||$account->mer_id!==$identity['mer_id'])throw new AuthUnavailableException();
        return $identity+['currency'=>$account->currency,'channel'=>'test','account_source'=>'test_config',
            'account_id'=>null,'account_ref'=>$account->account_ref,'account_version'=>$account->version];
    }

    private function knownReference(array $attempt): ?string
    {
        $reference=$attempt['attempt_ref']??null;
        if(($attempt['scope']??null)!==self::SCOPE||!is_string($reference)||!isset($this->attempts[$reference]))return null;
        $saved=$this->attempts[$reference];
        if(!is_string($attempt['binding_sha256']??null)||!hash_equals($saved['binding_sha256'],$attempt['binding_sha256'])
            ||($attempt['binding']??null)!==$saved['binding'])return null;
        return $reference;
    }

    private function assertScope(): void
    {
        PaymentAccount::assertTesting();
        if(app()!==$this->app)throw new AuthUnavailableException();
    }

    private static function result(string $reference, array $saved): array
    {
        return ['state'=>$saved['state'],'scope'=>self::SCOPE,'attempt_ref'=>$reference,
            'binding_sha256'=>$saved['binding_sha256'],'binding'=>$saved['binding'],
            'provider_trade_no'=>$saved['provider_trade_no']];
    }

    private static function unknown(): array
    {
        return ['state'=>'unknown','scope'=>self::SCOPE,'attempt_ref'=>null,'binding_sha256'=>null,'binding'=>null,'provider_trade_no'=>null];
    }

    private static function unknownOrder(): array
    {
        return ['state'=>'unknown','scope'=>self::SCOPE,'attempts'=>[]];
    }
}
