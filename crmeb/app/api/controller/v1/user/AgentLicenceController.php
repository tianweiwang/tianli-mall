<?php
declare(strict_types=1);
namespace app\api\controller\v1\user;

use app\Request;
use app\dao\marketplace\assets\CustomerAgentLicenceAssetDao;
use app\services\marketplace\assets\CustomerAgentLicenceAssetServices;
use app\services\marketplace\assets\CustomerAssetServices;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use crmeb\exceptions\AuthUnavailableException;
use think\Response;

final class AgentLicenceController
{
    public function upload(Request $request,CustomerAssetServices $assets):Response
    {
        $id=CustomerAgentLicenceAssetDao::integer($request->route('apply_id',null,null),0,2147483647);
        try{return app('json')->success('图片上传成功',$assets->uploadAgentLicence($request,(int)$request->uid(),$id));}
        catch(MerchantAttachmentOperationException $error){
            return Response::create(['status'=>503,'msg'=>'执照图片操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
        }
    }
    public function content(Request $request,CustomerAgentLicenceAssetServices $assets):Response
    {
        $id=CustomerAgentLicenceAssetDao::assetId($request->route('asset_id',null,null));
        return self::respond($assets->customerContent($request,(int)$request->uid(),$id));
    }
    /** Both authenticated readers use the same narrow binary response; never a public URL. */
    public static function respond(array $value):Response
    {
        if(array_keys($value)!==['bytes','media_type','byte_size','file_name']||!is_string($value['bytes'])||$value['bytes']===''
            ||!is_int($value['byte_size'])||$value['byte_size']!==strlen($value['bytes'])
            ||!in_array($value['media_type'],['image/jpg','image/jpeg','image/png','image/gif','image/webp'],true)
            ||!is_string($value['file_name'])||preg_match('/\Aagent-licence-[1-9][0-9]{0,19}\.(jpg|jpeg|png|gif|webp)\z/D',$value['file_name'])!==1)
            throw new AuthUnavailableException();
        $response=Response::create($value['bytes'],'html',200)->allowCache(false)->header([
            'Content-Type'=>$value['media_type'],'Content-Length'=>(string)$value['byte_size'],
            'Content-Disposition'=>'inline; filename="'.$value['file_name'].'"','Cache-Control'=>'private, no-store, max-age=0',
            'Pragma'=>'no-cache','Expires'=>'0','X-Content-Type-Options'=>'nosniff']);
        if($response->getContent()!==$value['bytes']||$response->getCode()!==200||$response->isAllowCache()!==false)
            throw new AuthUnavailableException();return $response;
    }
}
