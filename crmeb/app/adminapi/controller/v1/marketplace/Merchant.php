<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\MerchantAdminServices;
use think\Response;

final class Merchant
{
    public function __construct(private MerchantAdminServices $services) {}

    public function index(Request $request): Response
    { return self::success($this->services->listing($request->platformScope(), $request->get(false))); }
    public function read(Request $request): Response
    { return self::success($this->services->read($request->platformScope())); }
    public function create(Request $request): Response
    { return self::success($this->services->create($request->platformScope(), $request->post(false))); }
    public function update(Request $request): Response
    { return self::success($this->services->update($request->platformScope(), $request->put(false))); }
    public function review(Request $request): Response
    { return self::success($this->services->review($request->platformScope(), $request->put(false))); }
    public function flags(Request $request): Response
    { return self::success($this->services->flags($request->platformScope(), $request->put(false))); }
    public function createAdminEmployee(Request $request): Response
    { return self::success($this->services->createAdminEmployee($request->platformScope(), $request->post(false))); }
    public function reissuePasswordSetup(Request $request): Response
    { return self::success($this->services->reissuePasswordSetup($request->platformScope(), $request->post(false))); }

    private static function success(array $data): Response
    { return Response::create(['status'=>200, 'msg'=>'操作成功', 'data'=>$data], 'json', 200); }
}
