<?php
declare(strict_types=1);

namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\finance\FinanceBillsServices;
use think\Response;

final class FinanceStatements
{
    public function __construct(private FinanceBillsServices $services) {}

    public function read(Request $request): Response
    {
        $data = $this->services->readMerchantStatements($request->merchantContext(), $request->get(false));
        return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data], 'json', 200);
    }
}
