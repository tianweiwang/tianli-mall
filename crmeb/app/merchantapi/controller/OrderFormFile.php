<?php
declare(strict_types=1);
namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\assets\CustomerOrderFormReadServices;
use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use think\Response;

final class OrderFormFile
{
    public function __construct(private CustomerOrderFormReadServices $files){}
    public function read(Request $request):Response
    {return self::respond($request,$this->files->forMerchantOrder($request,$request->merchantContext(),self::root($request),self::asset($request)));}
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
            ||!is_string($value['file_name'])||preg_match('/\Aorder-form-[1-9][0-9]{0,19}\.(?:jpg|jpeg|png|gif|webp)\z/D',$value['file_name'])!==1)self::unavailable();
        $response=Response::create($value['bytes'],'html',200)->allowCache(false)->header([
            'Content-Type'=>$value['media_type'],'Content-Length'=>(string)$value['byte_size'],
            'Content-Disposition'=>'inline; filename="'.$value['file_name'].'"',
            'Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0','X-Content-Type-Options'=>'nosniff']);
        if($response->getContent()!==$value['bytes']||$response->getCode()!==200||$response->isAllowCache()!==false)self::unavailable();
        return $response;
    }
    private static function root(Request $request):int
    {
        $id=MarketplaceOrderInput::unsigned($request->route('root_id',null,null),'订单编号',2147483647);
        if($id<1)self::unavailable();return $id;
    }
    private static function asset(Request $request):string
    {
        $id=$request->route('asset_id',null,null);
        if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1
            ||(strlen($id)===20&&strcmp($id,'18446744073709551615')>0))self::unavailable();return $id;
    }
    private static function unavailable():never {throw new AuthUnavailableException();}
}
