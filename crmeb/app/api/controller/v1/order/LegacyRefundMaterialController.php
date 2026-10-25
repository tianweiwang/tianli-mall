<?php
declare(strict_types=1);
namespace app\api\controller\v1\order;

use app\Request;
use app\dao\marketplace\assets\CustomerLegacyRefundAssetDao as Assets;
use app\services\marketplace\assets\CustomerLegacyRefundAssetServices;
use app\services\marketplace\assets\CustomerLegacyRefundReadServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\exceptions\AuthUnavailableException;
use think\Response;

/** Fixed v0 material entrypoints under the original customer/mobile authentication groups. */
final class LegacyRefundMaterialController
{
    public function __construct(private CustomerLegacyRefundAssetServices $uploads,private CustomerLegacyRefundReadServices $reads){}
    public function uploadApplication(Request $request):Response
    {return $this->upload($request,'legacy_refund_evidence','order_id');}
    public function uploadShipping(Request $request):Response
    {return $this->upload($request,'refund_shipping_proof','refund_id');}
    private function upload(Request $request,string $purpose,string $key):Response
    {
        $id=Assets::integer($request->route($key,null,''),1,2147483647);
        try{
            $data=$this->uploads->upload($request,(int)$request->uid(),$purpose,$id);
            return Response::create(['status'=>200,'msg'=>'图片上传成功','data'=>$data],'json',200)->allowCache(false)
                ->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'图片上传结果未确认，请保留草稿并重新读取','data'=>$error->receipt],'json',503)
                ->allowCache(false)->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }
    }
    public function application(Request $request):Response
    {return self::respond($request,$this->reads->customerApplication($request,(int)$request->uid(),self::context($request,'order_id'),self::asset($request)));}
    public function customer(Request $request):Response
    {return self::respond($request,$this->reads->customerRefund($request,(int)$request->uid(),self::context($request,'refund_id'),self::asset($request)));}
    public function mobile(Request $request):Response
    {return self::respond($request,$this->reads->mobileRefund($request,(int)$request->uid(),self::context($request,'refund_id'),self::asset($request)));}
    private static function respond(Request $request,array $value):Response
    {
        if($request->method(true)==='POST'){
            if(array_keys($value)!==['read_ticket','issued_at_ms','expires_at_ms'])self::unavailable();
            return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$value],'json',200)->allowCache(false)
                ->header(['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0']);
        }
        if($request->method(true)!=='GET'||array_keys($value)!==['bytes','media_type','byte_size','file_name']
            ||!is_string($value['bytes'])||$value['bytes']===''||!is_int($value['byte_size'])
            ||$value['byte_size']!==strlen($value['bytes'])||!is_string($value['media_type'])
            ||!in_array($value['media_type'],['image/jpeg','image/jpg','image/png','image/gif','image/webp'],true)
            ||!is_string($value['file_name'])||preg_match('/\Alegacy-refund-[1-9][0-9]{0,19}\.(?:jpg|jpeg|png|gif|webp)\z/D',$value['file_name'])!==1)self::unavailable();
        $response=Response::create($value['bytes'],'html',200)->allowCache(false)->header([
            'Content-Type'=>$value['media_type'],'Content-Length'=>(string)$value['byte_size'],
            'Content-Disposition'=>'inline; filename="'.$value['file_name'].'"',
            'Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0','X-Content-Type-Options'=>'nosniff']);
        if($response->getContent()!==$value['bytes']||$response->getCode()!==200||$response->isAllowCache()!==false)self::unavailable();
        return $response;
    }
    private static function context(Request $request,string $key):int {return Assets::integer($request->route($key,null,''),1,2147483647);}
    private static function asset(Request $request):string {return Assets::assetId($request->route('asset_id',null,''));}
    private static function unavailable():never {throw new AuthUnavailableException();}
}
