<?php
declare(strict_types=1);
namespace app\adminapi\controller\v1\marketplace;

use app\Request;
use app\services\marketplace\finance\FinanceTransactionsServices;
use think\Response;

final class FinanceTransactions
{
    public function __construct(private FinanceTransactionsServices $services) {}
    public function read(Request $request): Response
    { return Response::create(['status'=>200,'msg'=>'操作成功','data'=>$this->services->read($request->platformScope(),$request->get(false))],'json',200); }
}
