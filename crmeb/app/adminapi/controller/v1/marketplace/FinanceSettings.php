<?php
declare(strict_types=1);

namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\finance\FinanceSettingsServices;
use think\Response;

final class FinanceSettings
{
    public function __construct(private FinanceSettingsServices $services) {}
    public function read(Request $request): Response
    { return self::success($this->services->read($request->platformScope(),$request->get(false))); }
    public function save(Request $request): Response
    { return self::success($this->services->save($request->platformScope(),$request->put(false))); }
    private static function success(array $data): Response
    { return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$data],'json',200); }
}
