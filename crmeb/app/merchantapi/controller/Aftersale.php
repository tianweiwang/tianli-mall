<?php
declare(strict_types=1);
namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\order\MarketplaceOrderRefundInput;
use app\services\marketplace\order\MarketplaceOrderRefundReadServices;
use app\services\marketplace\order\MarketplaceOrderRefundWriter;
use think\Response;

final class Aftersale
{
    public function __construct(private MarketplaceOrderRefundReadServices $reads,private MarketplaceOrderRefundWriter $writes){}
    public function index(Request $request):Response
    { return self::success($this->reads->pageForMerchant($request,$request->merchantContext(),$request->get(false))); }
    public function read(Request $request):Response
    { return self::success($this->reads->detailForMerchant($request,$request->merchantContext(),$request->route('id',null,null))); }
    public function approve(Request $request):Response {return $this->command($request,'approve');}
    public function reject(Request $request):Response {return $this->command($request,'reject');}
    public function acceptReturn(Request $request):Response {return $this->command($request,'accept_return');}
    private function command(Request $request,string $action):Response
    {
        $raw=MarketplaceOrderRefundInput::decode($request->getContent());
        return self::success($this->writes->forMerchant($request,$request->merchantContext(),$action,$request->route('id',null,null),$raw));
    }
    private static function success(array $data):Response
    {return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);}
}
