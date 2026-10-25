<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\product;

use app\Request;
use app\services\marketplace\product\LegacyProductWriteServices;
use app\services\marketplace\product\PlatformProductMediaServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use think\Response;

final class PlatformProductMedia
{
    public function __construct(private PlatformProductMediaServices $services) {}

    public function index(Request $request): Response
    {
        $id=LegacyProductWriteServices::routeId($request->route('product_id',null,null),true);
        return app('json')->success($this->services->listing($request,$id,$request->get(false)));
    }

    public function upload(Request $request): Response
    {
        $id=LegacyProductWriteServices::routeId($request->route('product_id',null,null),true);
        try {
            $src=$this->services->upload($request,$id,$request->post(false));
            return app('json')->success('上传成功',['src'=>$src]);
        } catch(MerchantAttachmentOperationException $error) {
            return Response::create(['status'=>503,'msg'=>'素材操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
        }
    }

    public function uploadVideo(Request $request): Response
    {
        $id=LegacyProductWriteServices::routeId($request->route('product_id',null,null),true);
        try {
            $src=$this->services->uploadVideo($request,$id,$request->post(false));
            $data=is_array($src)?$src:['src'=>$src];
            return app('json')->success(isset($data['src'])?'上传成功':'视频暂存已处理',$data);
        } catch(MerchantAttachmentOperationException $error) {
            return Response::create(['status'=>503,'msg'=>'素材操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
        }
    }

    public function uploadAudio(Request $request): Response
    {
        $id=LegacyProductWriteServices::routeId($request->route('product_id',null,null),true);
        try {
            $src=$this->services->uploadAudio($request,$id,$request->post(false));
            return app('json')->success('上传成功',['src'=>$src]);
        } catch(MerchantAttachmentOperationException $error) {
            return Response::create(['status'=>503,'msg'=>'素材操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
        }
    }

    public function onlineUpload(Request $request): Response
    {
        $id=LegacyProductWriteServices::routeId($request->route('product_id',null,null),true);
        try{
            $list=$this->services->onlineUpload($request,$id,$request->post(false));
            return app('json')->success('上传成功',['list'=>$list]);
        }catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'素材操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
        }
    }

    private static function productId(Request $request): int
    { return LegacyProductWriteServices::routeId($request->route('product_id',null,null),true); }

    public function categories(Request $request): Response
    { return app('json')->success($this->services->categories($request,self::productId($request),$request->get(false))); }

    public function createCategory(Request $request): Response
    {
        $this->services->saveCategory($request,self::productId($request),0,$request->post(false));
        return app('json')->success('添加成功');
    }
    public function updateCategory(Request $request): Response
    {
        $id=\app\services\marketplace\attachment\MerchantAttachmentInput::id($request->route('category_id',null,null));
        $this->services->saveCategory($request,self::productId($request),$id,$request->put(false));
        return app('json')->success('修改成功');
    }
    public function deleteCategory(Request $request): Response
    {
        $id=\app\services\marketplace\attachment\MerchantAttachmentInput::id($request->route('category_id',null,null));
        $this->services->deleteCategory($request,self::productId($request),$id,self::categoryDeleteSelector($request));
        return app('json')->success('删除成功');
    }
    public function rename(Request $request): Response
    {
        $id=\app\services\marketplace\attachment\MerchantAttachmentInput::id($request->route('att_id',null,null));
        $this->services->rename($request,self::productId($request),$id,$request->put(false));
        return app('json')->success('修改成功');
    }
    public function move(Request $request): Response
    {
        $this->services->move($request,self::productId($request),$request->put(false));
        return app('json')->success('移动成功');
    }
    public function delete(Request $request): Response
    {
        try{
            $this->services->delete($request,self::productId($request),$request->post(false));
            return app('json')->success('删除成功');
        }catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'素材操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
        }
    }

    private static function categoryDeleteSelector(Request $request): array
    {
        // Existing DELETE callers had no body contract; inspect only the optional type, leaving other old fields ignored.
        $query=$request->get(false);$body=$request->delete(false);$selected=null;$present=false;
        foreach([$query,$body]as$values)if(array_key_exists('type',$values)){
            $type=\app\services\marketplace\MerchantManagementInput::integer($values['type'],0,1,true);
            if($present&&$type!==$selected)\app\services\marketplace\attachment\MerchantAttachmentInput::invalid();$selected=$type;$present=true;
        }
        return $present?['type'=>$selected]:[];
    }
}
