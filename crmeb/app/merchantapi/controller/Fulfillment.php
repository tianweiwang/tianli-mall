<?php
declare(strict_types=1);

namespace app\merchantapi\controller;

use app\Request;
use app\services\marketplace\order\MerchantOrderInput;
use app\services\marketplace\order\MerchantOrderServices;
use crmeb\exceptions\ApiException;
use think\Response;

/** Fixed merchant package reads and express shipments; the original Order controller stays unchanged. */
final class Fulfillment
{
    public function __construct(private MerchantOrderServices $services){}

    private static function success(array $data):Response
    {
        return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200);
    }

    public function packages(Request $request):Response
    {
        $query=MerchantOrderInput::body($request->get(false),['page','limit']);
        if($request->getContent()!=='')MerchantOrderInput::invalid();
        return self::success($this->services->packages($request->merchantContext(),$request->route('id',null,null),array_key_exists('page',$query)?$query['page']:1,array_key_exists('limit',$query)?$query['limit']:20));
    }

    public function read(Request $request):Response
    {
        MerchantOrderInput::body($request->get(false),[]);if($request->getContent()!=='')MerchantOrderInput::invalid();
        return self::success($this->services->packageDetail($request->merchantContext(),$request->route('id',null,null)));
    }

    public function ship(Request $request):Response
    {
        MerchantOrderInput::body($request->get(false),[]);
        $raw=$request->getContent();$type=strtolower(trim(explode(';',(string)$request->header('Content-Type',''))[0]));
        if($type!=='application/json'||!is_string($raw)||strlen($raw)>65536||(ltrim($raw)[0]??'')!=='{')throw new ApiException('履约请求格式错误');
        return self::success($this->services->ship($request->merchantContext(),$request->route('id',null,null),$request->post(false)));
    }
}
