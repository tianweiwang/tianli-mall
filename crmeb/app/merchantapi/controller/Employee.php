<?php
declare(strict_types=1);

namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\EmployeeServices;
use app\services\marketplace\MerchantManagementInput;
use think\Response;

final class Employee
{
    public function __construct(private EmployeeServices $services) {}

    public function index(Request $request): Response
    {
        return self::success($this->services->listing($request->merchantContext(), $request->get(false)));
    }

    public function create(Request $request): Response
    {
        return self::success($this->services->create($request->merchantContext(), $request->post(false)));
    }

    public function update(Request $request): Response
    {
        $id = MerchantManagementInput::integer($request->route('id', null, null), 1, 4294967295, true);
        return self::success($this->services->update($request->merchantContext(), $id, $request->put(false)));
    }

    public function status(Request $request): Response
    {
        $id = MerchantManagementInput::integer($request->route('id', null, null), 1, 4294967295, true);
        return self::success($this->services->setStatus($request->merchantContext(), $id, $request->put(false)));
    }

    private static function success(array $data): Response
    {
        return Response::create(['status' => 200, 'msg' => '操作成功', 'data' => $data], 'json', 200);
    }
}
