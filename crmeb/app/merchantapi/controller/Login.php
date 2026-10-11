<?php
declare(strict_types=1);

namespace app\merchantapi\controller;

use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\Request;
use app\services\marketplace\EmployeeAuthServices;
use think\exception\HttpException;
use think\Response;

final class Login
{
    public function __construct(private EmployeeAuthServices $services)
    {
    }

    public function login(Request $request): Response
    {
        $result = $this->services->login(
            self::stringInput($request, 'account'),
            self::stringInput($request, 'password')
        );
        return self::success($result);
    }

    public function refresh(Request $request): Response
    {
        $request->merchantContext();
        return self::success($this->services->refresh(MerchantAuthTokenMiddleware::token($request)));
    }

    public function logout(Request $request): Response
    {
        $request->merchantContext();
        $this->services->logout(MerchantAuthTokenMiddleware::token($request));
        return self::success();
    }

    public function setupPassword(Request $request): Response
    {
        $this->services->setupPassword(
            self::stringInput($request, 'token'),
            self::stringInput($request, 'password')
        );
        return self::success();
    }

    private static function stringInput(Request $request, string $name): string
    {
        // Explicit null disables the legacy application's request filters.
        $value = $request->post($name, null, null);
        if (!is_string($value)) {
            throw new HttpException(422, '请求参数不正确', null, [], 422);
        }
        return $value;
    }

    private static function success(array $data = []): Response
    {
        return Response::create(['status' => 200, 'msg' => '操作成功', 'data' => $data], 'json', 200);
    }
}
