<?php
declare(strict_types=1);

namespace app\api\controller\v1\order;

use app\Request;
use app\services\marketplace\assets\CustomerOrderFormAssetServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\ApiException;
use think\Response;

/** Named confirmation upload; identity and the complete asset operation stay in the real service. */
final class OrderFormUploadController
{
    public function upload(Request $request,CustomerOrderFormAssetServices $assets):Response
    {
        try{
            $key=$request->route('key',null,null);
            if(!is_string($key))throw new ApiException('确认引用格式错误');
            $key=MarketplaceOrderInput::confirmationKey($key);
            $productId=MarketplaceOrderInput::unsigned($request->route('product_id',null,null),'表单商品',8388607);
            if($productId<1)throw new ApiException('表单商品格式错误');
            $fieldIndex=MarketplaceOrderInput::unsigned($request->route('field_index',null,null),'表单字段',9);
            $data=$assets->uploadForConfirmation($request,(int)$request->uid(),$key,$productId,$fieldIndex);
            return app('json')->success('图片上传成功',$data);
        }catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'图片操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
        }
    }
}
