<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\finance\FinanceBillsServices;
use think\Response;

final class FinanceBills
{
    public function __construct(private FinanceBillsServices $services) {}
    public function read(Request $request):Response
    {return self::success($this->services->read($request->platformScope(),$request->get(false)));}
    public function import(Request $request):Response
    {return self::success($this->services->import($request->platformScope(),$request->post(false)));}
    public function reconcile(Request $request):Response
    {return self::success($this->services->reconcile($request->platformScope(),$request->post(false)));}
    private static function success(array $data):Response
    {return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);}
}
