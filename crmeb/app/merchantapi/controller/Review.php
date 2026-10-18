<?php
declare(strict_types=1);
namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\order\MarketplaceOrderReviewInput;
use app\services\marketplace\order\MarketplaceOrderReviewServices;
use think\Response;

final class Review
{
    public function __construct(private MarketplaceOrderReviewServices $reviews){}
    public function index(Request $request):Response
    {return self::success($this->reviews->pageForMerchant($request,$request->merchantContext(),$request->get(false)));}
    public function read(Request $request):Response
    {return self::success($this->reviews->detailForMerchant($request,$request->merchantContext(),$request->route('id',null,null)));}
    public function reply(Request $request):Response
    {
        $body=MarketplaceOrderReviewInput::decode($request->getContent());
        return self::success($this->reviews->replyForMerchant($request,$request->merchantContext(),$request->route('id',null,null),$body));
    }
    private static function success(array $data):Response
    {return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);}
}
