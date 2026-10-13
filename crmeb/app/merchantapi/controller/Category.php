<?php
declare(strict_types=1);
namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\MerchantCategoryServices;
use app\services\marketplace\MerchantManagementInput;
use think\exception\HttpException;
use think\Response;

final class Category
{
    public function __construct(private MerchantCategoryServices $services) {}
    public function index(Request $request):Response
    {
        MerchantManagementInput::body($request->get(false),[]);
        if ($request->getContent()!=='') throw new HttpException(422,'请求参数不正确');
        return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$this->services->list($request->merchantContext())],'json',200);
    }
}
