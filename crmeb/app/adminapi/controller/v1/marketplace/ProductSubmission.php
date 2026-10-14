<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\PlatformProductReviewServices;
use app\services\marketplace\PlatformProductSubmissionServices;
use think\Response;

final class ProductSubmission
{
    public function __construct(private PlatformProductSubmissionServices $services) {}

    public function submitReview(Request $request): Response
    {
        $id = PlatformProductReviewServices::productId($request->route('id'));
        $data = $this->services->submitReview($request->platformScope(),$id,$request->put(false));
        return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);
    }
}
