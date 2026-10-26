<?php
declare(strict_types=1);
namespace app\api\controller\v1\order;

use app\Request;
use app\services\marketplace\assets\CustomerLegacyOrderFormAssetServices;
use app\services\marketplace\assets\CustomerLegacyOrderFormReadServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use think\Response;

/** Legacy confirmation upload and the three original authenticated API reader roles. */
final class LegacyOrderFormController
{
    public function upload(Request $request,CustomerLegacyOrderFormAssetServices $assets):Response
    {
        try{
            $productId=MarketplaceOrderInput::unsigned($request->route('product_id',null,null),'表单商品',8388607);
            if($productId<1)throw new AuthUnavailableException();
            $fieldIndex=MarketplaceOrderInput::unsigned($request->route('field_index',null,null),'表单字段',9);
            $data=$assets->uploadForConfirmation($request,(int)$request->uid(),self::key($request),$productId,$fieldIndex);
            return Response::create(['status'=>200,'msg'=>'图片上传成功','data'=>$data],'json',200)->allowCache(false)
                ->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'图片操作结果未确认，请保留本次资料','data'=>$error->receipt],'json',503)->allowCache(false)
                ->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }
    }
    public function confirmation(Request $request,CustomerLegacyOrderFormReadServices $files):Response
    {return OrderFormReadController::respond($request,$files->forConfirmation($request,(int)$request->uid(),self::key($request),self::asset($request)));}
    public function customer(Request $request,CustomerLegacyOrderFormReadServices $files):Response
    {return OrderFormReadController::respond($request,$files->forCustomerOrder($request,(int)$request->uid(),self::order($request),self::asset($request)));}
    public function mobile(Request $request,CustomerLegacyOrderFormReadServices $files):Response
    {return OrderFormReadController::respond($request,$files->forMobileOrder($request,(int)$request->uid(),self::order($request),self::asset($request)));}
    private static function key(Request $request):string
    {
        $key=$request->route('key',null,null);if(!is_string($key))throw new AuthUnavailableException();
        return MarketplaceOrderInput::confirmationKey($key);
    }
    private static function order(Request $request):int
    {
        $id=MarketplaceOrderInput::unsigned($request->route('order_id',null,null),'订单编号',2147483647);
        if($id<1)throw new AuthUnavailableException();return $id;
    }
    private static function asset(Request $request):string
    {
        $id=$request->route('asset_id',null,null);
        if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1
            ||(strlen($id)===20&&strcmp($id,'18446744073709551615')>0))throw new AuthUnavailableException();
        return $id;
    }
}
