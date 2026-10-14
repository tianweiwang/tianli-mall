<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\PlatformProductReviewServices;
use app\services\marketplace\product\PlatformProductStockServices;
use think\Response;

final class ProductStock
{
    public function __construct(private PlatformProductStockServices $services) {}

    public function adjust(Request $request): Response
    {
        $id=PlatformProductReviewServices::productId($request->route('id'));
        $data=$this->services->adjust($request->platformScope(),$id,$request->post(false));
        return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);
    }
}
