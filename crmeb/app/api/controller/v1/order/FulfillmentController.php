<?php
declare(strict_types=1);

namespace app\api\controller\v1\order;

use app\Request;
use app\services\marketplace\order\MarketplaceOrderFulfillmentServices;
use app\services\order\StoreOrderServices;
use crmeb\exceptions\ApiException;

/** Member force-auth routes only. The caller never supplies a UID, merchant or actor mode. */
final class FulfillmentController
{
    public function __construct(private StoreOrderServices $orders,private MarketplaceOrderFulfillmentServices $fulfillment){}

    public function read(Request $request,string $childNo)
    {
        if($request->get(false)!==[]||$request->getContent()!=='')throw new ApiException('履约请求参数错误');
        return app('json')->success($this->orders->readCustomerPackageForReceipt((int)$request->uid(),$childNo));
    }

    public function receipt(Request $request,string $childNo)
    {
        if($request->get(false)!==[])throw new ApiException('履约请求参数错误');
        $raw=$request->getContent();$type=strtolower(trim(explode(';',(string)$request->header('Content-Type',''))[0]));
        if($type!=='application/json'||!is_string($raw)||strlen($raw)>65536||(ltrim($raw)[0]??'')!=='{')throw new ApiException('履约请求格式错误');
        return app('json')->success($this->fulfillment->receivePackageForCustomer((int)$request->uid(),$childNo,$request->post(false)));
    }
}
