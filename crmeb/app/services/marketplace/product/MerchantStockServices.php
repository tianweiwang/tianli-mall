<?php
declare(strict_types=1);
namespace app\services\marketplace\product;
use app\dao\marketplace\product\MerchantProductDao;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\EmployeeAuthServices;
use app\services\marketplace\MerchantManagementInput;
use app\services\marketplace\context\MerchantContext;
use crmeb\services\auth\AccountChangeGuard;
final class MerchantStockServices
{
    public function __construct(private EmployeeAuthServices $authentication,private MerchantProductDao $products,private AuditLogServices $audit) {}
    public function adjust(MerchantContext $ctx,int $productId,string $unique,int $delta,string $reason): void
    {
        $ctx->require('stock.adjust');$this->authentication->authorizeAction($ctx,'stock.adjust');MerchantProductInput::integer($productId,1,MerchantProductInput::ROOT_MAX);MerchantProductInput::text($unique,8,true);if(preg_match('/\A[a-zA-Z0-9]{8}\z/D',$unique)!==1)MerchantProductInput::invalid();MerchantProductInput::integer($delta,-MerchantProductInput::STOCK_MAX,MerchantProductInput::STOCK_MAX);if($delta===0)MerchantProductInput::invalid();$reason=MerchantProductInput::text($reason,255,true);MerchantProductInput::text($ctx->requestId,64,true);MerchantProductInput::integer($ctx->employeeId,1,4294967295);
        AccountChangeGuard::runMany('merchantapi',[],function()use($ctx,$productId,$unique,$delta,$reason):void{$this->authentication->authorizeAction($ctx,'stock.adjust',true);MerchantManagementInput::write(function()use($ctx,$productId,$unique,$delta,$reason):void{$this->products->adjust($ctx,$productId,$unique,$delta,$reason);$this->audit->record('merchant',$ctx->employeeId,$ctx->merId,'stock.adjust','product',(string)$productId,'success',['changed_fields'=>['stock'],'employee_id'=>$ctx->employeeId]);});});
    }
}
