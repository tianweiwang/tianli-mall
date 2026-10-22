<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\product;

use app\Request;
use app\services\marketplace\product\LegacyProductWriteServices;
use app\services\marketplace\product\ProductMediaScanServices;
use app\services\marketplace\attachment\MerchantAttachmentInput;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use think\Response;

/** Five authenticated PC actions; the anonymous phone has a separate stage-only entry. */
final class PlatformProductMediaScan
{
    public function __construct(private ProductMediaScanServices $services) {}
    private static function product(Request $request): int
    { return LegacyProductWriteServices::routeId($request->route('product_id',null,null),true); }
    private static function id(Request $request,string $name): string
    {
        $id=$request->route($name,null,null);
        if(!is_string($id)||preg_match('/\A[a-f0-9]{32}\z/D',$id)!==1)MerchantAttachmentInput::invalid();
        return $id;
    }
    public function issue(Request $request): Response
    { return app('json')->success($this->services->issue($request,self::product($request),$request->post(false))); }
    public function poll(Request $request): Response
    { return app('json')->success($this->services->poll($request,self::product($request),self::id($request,'lease_id'))); }
    public function preview(Request $request): Response
    {
        $image=$this->services->preview($request,self::product($request),self::id($request,'lease_id'),self::id($request,'stage_id'));
        return Response::create($image['bytes'])->header(['Content-Type'=>$image['mime'],'Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function claim(Request $request): Response
    {
        try{
            $data=$this->services->claim($request,self::product($request),self::id($request,'lease_id'),$request->post(false));
            return app('json')->success($data['state']==='claimed'?'上传成功':'正在处理，请稍候',$data);
        }catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'认领尚未确认，请保留本次结果','data'=>['state'=>'unknown','receipt'=>$error->receipt]],'json',503);
        }
    }
    public function close(Request $request): Response
    {
        try{
            $data=$this->services->close($request,self::product($request),self::id($request,'lease_id'));
            return app('json')->success($data['state']==='closed'?'已关闭':'关闭处理中',$data);
        }catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'关闭尚未确认，请保留本次结果','data'=>['state'=>'unknown','receipt'=>$error->receipt]],'json',503);
        }
    }
}
