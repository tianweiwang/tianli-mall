<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\assets\PlatformAssetServices;
use app\services\marketplace\product\PlatformProductMediaServices;
use app\services\marketplace\product\LegacyProductWriteServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use think\Response;

final class PlatformAsset
{
    public function __construct(private PlatformAssetServices $services) {}
    public function index(Request $request): Response { return app('json')->success($this->services->listing($request,$request->get(false))); }
    public function categories(Request $request): Response { return app('json')->success($this->services->categories($request,$request->get(false))); }
    public function upload(Request $request): Response
    {
        try{return app('json')->success('上传成功',$this->services->upload($request,$request->post(false)));}
        catch(MerchantAttachmentOperationException $error){return self::unknown($error);}
    }
    public function importProduct(Request $request): Response
    {
        $id=LegacyProductWriteServices::routeId($request->route('id',null,null),true);
        try{$list=app()->make(PlatformProductMediaServices::class)->importPlatformImage($request,$id,$request->post(false));return app('json')->success('导入成功',['list'=>$list]);}
        catch(MerchantAttachmentOperationException $error){return self::unknown($error);}
    }
    private static function unknown(MerchantAttachmentOperationException $error): Response
    { return Response::create(['status'=>503,'msg'=>'素材操作未完成，请保留本次结果','data'=>$error->receipt],'json',503); }
}
