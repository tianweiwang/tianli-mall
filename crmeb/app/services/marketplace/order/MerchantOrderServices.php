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

    public function packages(MerchantContext $ctx,mixed $rootId,mixed $page=1,mixed $limit=20):array
    {
        return $this->fulfillment->readMerchantPackages($ctx,$rootId,$page,$limit);
    }

    public function packageDetail(MerchantContext $ctx,mixed $childId):array
    {
        return $this->fulfillment->readMerchantPackage($ctx,$childId);
    }

    public function ship(MerchantContext $ctx,mixed $rootId,array $publicBody):array
    {
        return $this->fulfillment->ship($ctx,$rootId,MarketplaceOrderFulfillmentInput::shipPublic($rootId,$publicBody));
    }

    public function detail(MerchantContext $ctx,mixed $orderId):array
    {
        return $this->fulfillment->readMerchantRoot($ctx,$orderId);
    }
}
