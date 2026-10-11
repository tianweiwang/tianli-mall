<?php
declare(strict_types=1);

namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\EmployeeAuthServices;
use think\Response;

final class Me
{
    public function __construct(private EmployeeAuthServices $services)
    {
    }

    public function index(Request $request): Response
    {
        return Response::create([
            'status' => 200,
            'msg' => '操作成功',
            'data' => $this->services->me($request->merchantContext()),
        ], 'json', 200);
    }
}
