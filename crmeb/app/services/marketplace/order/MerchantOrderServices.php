<?php
declare(strict_types=1);

namespace app\services\marketplace\order;

use app\dao\marketplace\order\MerchantOrderDao;
use app\services\marketplace\context\MerchantContext;
use app\services\marketplace\EmployeeAuthServices;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;

final class MerchantOrderServices
{
    public function __construct(private MerchantOrderDao $orders,private EmployeeAuthServices $authentication) {}

    private function authorize(MerchantContext $ctx): void
    {
        $ctx->require('order.read');$this->authentication->authorizeAction($ctx,'order.read',false);
    }

    public function list(MerchantContext $ctx,array $query=[]): array
    {
        $this->authorize($ctx);$input=MerchantOrderInput::listing($query);
        try{
            $data=$this->orders->listing($ctx,$input['filter'],$input['page'],$input['limit']);
            if(!is_int($data['count'])||$data['count']<0)throw new AuthUnavailableException();
            return ['list'=>array_map(static fn(array $order):array=>MarketplaceOrderSnapshotProjection::merchantOrder($order,$order['items']),$data['list']),
                'count'=>$data['count']];
        }catch(AuthUnavailableException|MerchantNotFoundException $error){throw $error;}
        catch(\Throwable $error){throw new AuthUnavailableException();}
    }

    public function detail(MerchantContext $ctx,mixed $orderId): array
    {
        $this->authorize($ctx);$id=MerchantOrderInput::id($orderId);
        try{
            $order=$this->orders->find($ctx,$id);
            return MarketplaceOrderSnapshotProjection::merchantOrder($order,$order['items'],true);
        }catch(AuthUnavailableException|MerchantNotFoundException $error){throw $error;}
        catch(\Throwable $error){throw new AuthUnavailableException();}
    }
}
