<?php
declare(strict_types=1);
namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\order\MarketplaceAftersaleFileServices;
use think\Response;

final class AftersaleFile
{
    public function __construct(private MarketplaceAftersaleFileServices $files){}
    public function ticket(Request $request):Response
    {return self::success($this->files->ticketForMerchant($request,$request->merchantContext(),$request->route('file_id',null,null)));}
    public function content(Request $request):Response
    {return $this->files->contentForMerchant($request,$request->merchantContext(),$request->route('file_id',null,null));}
    private static function success(array $data):Response
    {return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);}
}
