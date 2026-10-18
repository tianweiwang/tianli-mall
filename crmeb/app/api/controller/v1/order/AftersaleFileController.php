<?php
declare(strict_types=1);
namespace app\api\controller\v1\order;

use app\Request;
use app\services\marketplace\order\MarketplaceAftersaleFileServices;
use think\Response;

final class AftersaleFileController
{
    public function __construct(private MarketplaceAftersaleFileServices $files){}
    public function upload(Request $request):Response
    {return app('json')->success($this->files->uploadForCustomer($request,(int)$request->uid(),$request->route('root_id',null,null)));}
    public function ticket(Request $request):Response
    {return app('json')->success($this->files->ticketForCustomer($request,(int)$request->uid(),$request->route('file_id',null,null)));}
    public function content(Request $request):Response
    {return $this->files->contentForCustomer($request,(int)$request->uid(),$request->route('file_id',null,null));}
}
