<?php
declare(strict_types=1);
namespace app\merchantapi\controller;
use app\Request;
use app\services\marketplace\product\MerchantProductInput as Input;
use app\services\marketplace\product\MerchantProductServices;
use think\Response;
final class Product
{
    public function __construct(private MerchantProductServices $services) {}
    private static function success(array $data=[]): Response { return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200); }
    private static function id(Request $request): int { return Input::integer($request->route('id',null,null),1,Input::ROOT_MAX,true); }
    public function index(Request $request): Response
    {
        $query=Input::body($request->get(false),['page','limit','keyword','review_status','is_del']);$page=Input::integer($query['page']??1,1,2147483647,true);$limit=Input::integer($query['limit']??20,1,100,true);unset($query['page'],$query['limit']);return self::success($this->services->list($request->merchantContext(),$query,$page,$limit));
    }
    public function read(Request $request): Response { return self::success($this->services->detail($request->merchantContext(),self::id($request))); }
    public function create(Request $request): Response { return self::success(['id'=>$this->services->save($request->merchantContext(),0,$request->post(false))]); }
    public function update(Request $request): Response { return self::success(['id'=>$this->services->save($request->merchantContext(),self::id($request),$request->put(false))]); }
    public function submitReview(Request $request): Response { Input::body($request->put(false),[]);$this->services->submitReview($request->merchantContext(),self::id($request));return self::success(); }
    public function show(Request $request): Response { $body=Input::body($request->put(false),['show'],['show']);$this->services->setShow($request->merchantContext(),self::id($request),(bool)Input::integer($body['show'],0,1));return self::success(); }
    public function batchShow(Request $request): Response { $body=Input::body($request->put(false),['ids','show'],['ids','show']);$this->services->batchSetShow($request->merchantContext(),Input::ids($body['ids']),(bool)Input::integer($body['show'],0,1));return self::success(); }
    public function delete(Request $request): Response { Input::body($request->delete(false),[]);$this->services->delete($request->merchantContext(),self::id($request));return self::success(); }

    private static function mediaId(Request $request): int
    {
        return Input::integer($request->route('id',null,null),0,Input::ROOT_MAX,true);
    }
    public function uploadVideo(Request $request): Response
    {
        try{return self::success($this->services->uploadVideo($request,self::mediaId($request)));}
        catch(\app\services\marketplace\attachment\MerchantAttachmentOperationException $error){return self::mediaIncomplete($error);}
    }
    public function uploadAudio(Request $request): Response
    {
        try{return self::success($this->services->uploadAudio($request,self::mediaId($request)));}
        catch(\app\services\marketplace\attachment\MerchantAttachmentOperationException $error){return self::mediaIncomplete($error);}
    }
    private static function mediaIncomplete(\app\services\marketplace\attachment\MerchantAttachmentOperationException $error): Response
    {
        return Response::create(['status'=>503,'msg'=>'素材操作未完成，请保留本次结果','data'=>$error->receipt],'json',503);
    }
}
