<?php
declare(strict_types=1);

namespace crmeb\services\pay\marketplace;

use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;

/** Only this instance knows its attempts. No database/cache persistence, SDK, paid write or successful refund. */
final class TestPaymentDriver implements PaymentAdapter
{
    private const SCOPE='testing_process_only';
    private array $attempts=[];
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
            'idempotency_key'=>$idempotencyKey,'request_digest'=>$digest];
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
        $this->attempts[$reference]['state']='closed';
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

    public function createRefund(array $originalPaymentAttempt, array $actualRefund, string $idempotencyKey): array
    {
        $this->assertScope();throw new \LogicException('MARKETPLACE_TEST_REFUND_UNSUPPORTED');
    }

    public function queryRefund(array $refundAttempt): array
    {
        $this->assertScope();throw new \LogicException('MARKETPLACE_TEST_REFUND_UNSUPPORTED');
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
            'binding_sha256'=>$saved['binding_sha256'],'binding'=>$saved['binding'],'provider_trade_no'=>null];
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
