<?php
declare(strict_types=1);
namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\MerchantAttachmentServices;
use app\services\marketplace\MerchantManagementInput;
use app\services\marketplace\attachment\MerchantAttachmentInput as Input;
use app\services\marketplace\attachment\MerchantAttachmentOperationException;
use think\Response;

final class Attachment
{
    public function __construct(private MerchantAttachmentServices $services) {}
    public function index(Request $request): Response
    {
        $query=Input::body($request->get(false),['category_id','page','limit']);
        return self::success($this->services->list($request->merchantContext(),Input::id($query['category_id']??0,true),
            MerchantManagementInput::integer($query['page']??1,1,2147483647,true),MerchantManagementInput::integer($query['limit']??20,1,100,true)));
    }
    public function categories(Request $request): Response
    {
        Input::body($request->get(false),[]);
        return self::success($this->services->categories($request->merchantContext()));
    }
    public function createCategory(Request $request): Response
    {
        return self::success($this->services->saveCategory($request->merchantContext(),0,$request->post(false)));
    }
    public function updateCategory(Request $request): Response
    {
        return self::success($this->services->saveCategory($request->merchantContext(),Input::id($request->route('id',null,null)),$request->put(false)));
    }
    public function upload(Request $request): Response
    {
        try { return self::success($this->services->upload($request->merchantContext(),$request->post(false))); }
        catch (MerchantAttachmentOperationException $error) { return self::incomplete($error); }
    }
    public function delete(Request $request): Response
    {
        $body=Input::body($request->delete(false),['ids'],['ids']);
        try { return self::success($this->services->delete($request->merchantContext(),Input::ids($body['ids']))); }
        catch (MerchantAttachmentOperationException $error) { return self::incomplete($error); }
    }
    private static function success(array $data): Response
    {
        return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);
    }
    private static function incomplete(MerchantAttachmentOperationException $error): Response
    {
        return Response::create(['status'=>503,'msg'=>'素材操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
    }
}
