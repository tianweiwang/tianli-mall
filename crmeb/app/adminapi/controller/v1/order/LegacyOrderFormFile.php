<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\order;

use app\Request;
use app\api\controller\v1\order\OrderFormReadController;
use app\services\marketplace\assets\CustomerLegacyOrderFormReadServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use think\Response;

final class LegacyOrderFormFile
{
    public function read(Request $request,CustomerLegacyOrderFormReadServices $files):Response
    {
        $id=MarketplaceOrderInput::unsigned($request->route('order_id',null,null),'订单编号',2147483647);
        $asset=$request->route('asset_id',null,null);
        if($id<1||!is_string($asset))throw new AuthUnavailableException();
        return OrderFormReadController::respond($request,$files->forPlatformOrder($request,$id,$asset));
    }
}
