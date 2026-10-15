<?php
declare(strict_types=1);

namespace crmeb\services\pay\marketplace;

use crmeb\exceptions\AuthUnavailableException;
use think\facade\Config;

/** Immutable test configuration, never an institution-verified or database payment-account row. */
final readonly class PaymentAccount
{
    public string $source;
    public ?int $account_id;
    public string $account_ref;
    public int $version;
    public int $mer_id;
    public string $channel;
    public string $currency;
    public string $verification_scope;

    private function __construct(int $merId, string $reference, int $version, string $currency)
    {
        $this->source='test_config';$this->account_id=null;$this->account_ref=$reference;
        $this->version=$version;$this->mer_id=$merId;$this->channel='test';$this->currency=$currency;
        $this->verification_scope='test_driver_only';
    }

    public static function testing(int $actualMerId, string $reference, int $version, string $currency): self
    {
        self::assertTesting();
        if($actualMerId<1||$actualMerId>4294967295||$version<1||$version>2147483647
            ||strlen($reference)>128||preg_match('/\Atest:[A-Za-z0-9][A-Za-z0-9_.:-]*\z/D',$reference)!==1
            ||$currency!=='CNY')throw new AuthUnavailableException();
        return new self($actualMerId,$reference,$version,$currency);
    }

    /** Raw process environment is intentional: Think Env's PHP_ fallback is not APP_ENV. */
    public static function assertTesting(): void
    {
        if(getenv('APP_ENV')!=='testing'||Config::get('marketplace.payment.test_enabled',false)!==true)
            throw new AuthUnavailableException();
    }

    public function snapshot(): array
    {
        return ['source'=>$this->source,'account_id'=>$this->account_id,'account_ref'=>$this->account_ref,
            'version'=>$this->version,'mer_id'=>$this->mer_id,'channel'=>$this->channel,'currency'=>$this->currency,
            'verification_scope'=>$this->verification_scope];
    }
}
