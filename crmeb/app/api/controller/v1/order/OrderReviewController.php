<?php
declare(strict_types=1);
namespace app\api\controller\v1\order;

use app\Request;
use app\services\marketplace\order\MarketplaceOrderReviewInput;
use app\services\marketplace\order\MarketplaceOrderReviewServices;
use think\Response;

final class OrderReviewController
{
    public function __construct(private MarketplaceOrderReviewServices $reviews){}
    public function quote(Request $request):Response
    {
        return app('json')->success($this->reviews->quoteForCustomer($request,(int)$request->uid(),
            $request->route('root_id',null,null),$request->route('item_id',null,null)));
    }
    public function create(Request $request):Response
    {
        $body=MarketplaceOrderReviewInput::decode($request->getContent());
        return app('json')->success($this->reviews->createForCustomer($request,(int)$request->uid(),
            $request->route('root_id',null,null),$request->route('item_id',null,null),$body));
    }
}
