<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\PlatformProductReviewServices;
use think\Response;

final class ProductReview
{
    public function __construct(private PlatformProductReviewServices $services) {}

    public function index(Request $request): Response
    { return self::success($this->services->listing($request->platformScope(), $request->get(false))); }

    public function review(Request $request): Response
    {
        $id = PlatformProductReviewServices::productId($request->route('id'));
        return self::success($this->services->review($request->platformScope(), $id, $request->put(false)));
    }

    public function offShelf(Request $request): Response
    {
        $id = PlatformProductReviewServices::productId($request->route('id'));
        return self::success($this->services->offShelf($request->platformScope(), $id, $request->put(false)));
    }

    private static function success(array $data): Response
    { return Response::create(['status'=>200, 'msg'=>'操作成功', 'data'=>$data], 'json', 200); }
}
