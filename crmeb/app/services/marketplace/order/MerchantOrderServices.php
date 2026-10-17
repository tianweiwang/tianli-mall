<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\services\marketplace\context\MerchantContext;

/** Actual merchant authorization, saved facts and DTO now share the owned native read service. */
final class MerchantOrderServices
{
    public function __construct(private MarketplaceOrderFulfillmentServices $fulfillment) {}

    public function list(MerchantContext $ctx,array $query=[]):array
    {
        return $this->fulfillment->readMerchantPage($ctx,$query);
    }

    public function detail(MerchantContext $ctx,mixed $orderId):array
    {
        return $this->fulfillment->readMerchantRoot($ctx,$orderId);
    }
}
