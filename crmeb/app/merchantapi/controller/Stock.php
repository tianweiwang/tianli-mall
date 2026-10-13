<?php
declare(strict_types=1);
namespace app\merchantapi\controller;
use app\Request;
use app\services\marketplace\product\MerchantProductInput as Input;
use app\services\marketplace\product\MerchantStockServices;
use think\Response;
final class Stock
{
    public function __construct(private MerchantStockServices $services) {}
    public function adjust(Request $request): Response
    {
        $body=Input::body($request->put(false),['unique','delta','reason'],['unique','delta','reason']);$id=Input::integer($request->route('id',null,null),1,Input::ROOT_MAX,true);$this->services->adjust($request->merchantContext(),$id,Input::text($body['unique'],8,true),Input::integer($body['delta'],-Input::STOCK_MAX,Input::STOCK_MAX),Input::text($body['reason'],255,true));return Response::create(['status'=>200,'msg'=>'操作成功','data'=>[]],'json',200);
    }
}
