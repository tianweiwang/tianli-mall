<?php
declare(strict_types=1);
namespace app\api\controller\v1\order;

use app\Request;
use app\services\marketplace\assets\CustomerAssetServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use app\services\marketplace\order\MarketplaceOrderReviewInput;
use app\services\marketplace\order\MarketplaceOrderReviewServices;
use think\Response;

final class OrderReviewController
{
    public function __construct(private MarketplaceOrderReviewServices $reviews){}
    public function quote(Request $request):Response
    {
        return app('json')->success($this->reviews->quoteForCustomer($request,(int)$request->uid(),
            $request->route('root_id',null,null),$request->route('item_id',null,null)));
    }
    public function create(Request $request):Response
    {
        $body=MarketplaceOrderReviewInput::decode($request->getContent());
        return app('json')->success($this->reviews->createForCustomer($request,(int)$request->uid(),
            $request->route('root_id',null,null),$request->route('item_id',null,null),$body));
    }
    public function upload(Request $request,CustomerAssetServices $assets):Response
    {
        try{
            $data=$assets->uploadForReview($request,(int)$request->uid(),
                MarketplaceOrderReviewInput::id($request->route('root_id',null,null)),MarketplaceOrderReviewInput::id($request->route('item_id',null,null)));
            return app('json')->success('图片上传成功',$data);
        }catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'图片操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
        }
    }
}
