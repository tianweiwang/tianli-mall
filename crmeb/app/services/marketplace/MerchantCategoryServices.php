<?php
declare(strict_types=1);
namespace app\services\marketplace;

use app\services\marketplace\category\MerchantCategoryPolicy;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthUnavailableException;
use think\facade\Db;

final class MerchantCategoryServices
{
    public function __construct(private EmployeeAuthServices $authentication) {}
    public function list(MerchantContext $ctx):array
    {
        $ctx->require('product.read');$this->authentication->authorizeAction($ctx,'product.read',false);
        try {
            $rows=Db::name('store_category')->field(['id','pid','cate_name','sort','is_show'])
                ->order('sort','desc')->order('id','desc')->limit(MerchantCategoryPolicy::ROW_MAX+1)->select()->toArray();
            return MerchantCategoryPolicy::tree($rows);
        } catch (\Throwable $error) { throw new AuthUnavailableException(); }
    }
}
