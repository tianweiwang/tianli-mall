<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\order\MarketplaceAftersaleFileServices;
use think\Response;

final class AftersaleFile
{
    public function __construct(private MarketplaceAftersaleFileServices $files){}
    public function ticket(Request $request):Response
    {return self::success($this->files->ticketForPlatform($request,$request->platformScope(),$request->route('file_id',null,null)));}
    public function content(Request $request):Response
    {return $this->files->contentForPlatform($request,$request->platformScope(),$request->route('file_id',null,null));}
    private static function success(array $data):Response
    {return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);}
}
