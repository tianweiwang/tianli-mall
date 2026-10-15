<?php
declare(strict_types=1);

namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\order\MerchantOrderInput;
use app\services\marketplace\order\MerchantOrderServices;
use think\Response;

final class Order
{
    public function __construct(private MerchantOrderServices $services) {}

    private static function success(array $data): Response
    {
        return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);
    }

    public function index(Request $request): Response
    {
        if($request->getContent()!=='')MerchantOrderInput::invalid();
        return self::success($this->services->list($request->merchantContext(),$request->get(false)));
    }

    public function read(Request $request): Response
    {
        MerchantOrderInput::body($request->get(false),[]);
        if($request->getContent()!=='')MerchantOrderInput::invalid();
        return self::success($this->services->detail($request->merchantContext(),$request->route('id',null,null)));
    }
}
