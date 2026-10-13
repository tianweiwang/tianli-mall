<?php
declare(strict_types=1);
namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\MerchantFreightServices;
use app\services\marketplace\freight\MerchantFreightInput as Input;
use think\Response;

final class Freight
{
    public function __construct(private MerchantFreightServices $services) {}
    public function index(Request $request):Response { return self::success($this->services->list($request->merchantContext(),$request->get(false))); }
    public function read(Request $request):Response
    {
        Input::body($request->get(false),[]);return self::success($this->services->detail($request->merchantContext(),Input::id($request->route('id',null,null))));
    }
    public function create(Request $request):Response { return self::success($this->services->save($request->merchantContext(),0,$request->post(false))); }
    public function update(Request $request):Response { return self::success($this->services->save($request->merchantContext(),Input::id($request->route('id',null,null)),$request->put(false))); }
    public function delete(Request $request):Response
    {
        Input::body($request->delete(false),[]);return self::success($this->services->delete($request->merchantContext(),Input::id($request->route('id',null,null))));
    }
    public function cities(Request $request):Response { Input::body($request->get(false),[]);return self::success($this->services->cities($request->merchantContext())); }
    private static function success(array $data):Response { return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200); }
}
