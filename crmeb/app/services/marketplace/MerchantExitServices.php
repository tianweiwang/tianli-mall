<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\dao\marketplace\EmployeeDao;
use app\dao\marketplace\MerchantDao;
use app\dao\marketplace\MerchantExitDao;
use app\dao\marketplace\scope\PlatformScope;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use crmeb\services\auth\AccountChangeGuard;
use think\exception\HttpException;
use Throwable;

/** Platform-authorized exit; a precheck is information, never a cached close capability. */
final class MerchantExitServices
{
    private const PRECHECK='marketplace-merchants-exit-precheck';
    private const CLOSE='marketplace-merchants-close';

    public function __construct(private MerchantDao $merchants,private EmployeeDao $employees,
        private MerchantExitDao $facts,private PlatformAccessServices $access,private AuditLogServices $audit) {}

    public function precheck(PlatformScope $scope,array $query=[]): array
    {
        try{
            $this->access->authorizeScope($scope,self::PRECHECK);$merId=self::target($scope);
            MerchantManagementInput::body($query,[]);
            $result=$this->facts->precheck($merId);
            $this->access->authorizeScope($scope,self::PRECHECK);return $result;
        }catch(AuthException|HttpException $error){throw $error;}
        catch(Throwable $error){throw new AuthUnavailableException();}
    }

    public function close(PlatformScope $scope,array $body=[]): array
    {
        try{
            $this->access->authorizeScope($scope,self::CLOSE);$merId=self::target($scope);
            MerchantManagementInput::body($body,[]);
            return AccountChangeGuard::runManyAfterValidation('merchantapi',function()use($scope,$merId):array{
                // A locking current read precedes authorization's possibly older RR view.
                $merchant=$this->merchants->findForPlatform($scope,true);
                $this->access->authorizeScope($scope,self::CLOSE);
                if($merchant===null)throw new MerchantNotFoundException();
                if($merchant['closed_at']!==null)return ['ids'=>[],'value'=>['merchant'=>$merchant,'already_closed'=>true,'ids'=>[]]];
                $precheck=$this->facts->precheck($merId,$merchant);
                if($precheck['can_close']!==true)throw new HttpException(422,'商家仍有未完成或尚未核清的退出责任，请重新查看预检');
                $ids=$this->employees->idsForPlatformMerchant($scope,true);
                return ['ids'=>$ids,'value'=>['merchant'=>$merchant,'already_closed'=>false,'ids'=>$ids]];
            },function(array $prepared)use($scope,$merId):array{
                return MerchantManagementInput::write(function()use($scope,$merId,$prepared):array{
                    $before=$prepared['merchant'];
                    if($this->merchants->findForPlatform($scope,true)!==$before)throw new AuthUnavailableException();
                    if($prepared['already_closed'])return self::closed($merId,$before['closed_at'],true);
                    if($this->employees->idsForPlatformMerchant($scope,true)!==$prepared['ids'])throw new AuthUnavailableException();
                    $now=time();
                    if($this->merchants->closeForPlatform($scope,$now)!==1)throw new AuthUnavailableException();
                    $after=$this->merchants->findForPlatform($scope);
                    if($after!==array_replace($before,['closed_at'=>$now,'accept_orders'=>0,'update_time'=>$now]))throw new AuthUnavailableException();
                    $this->audit->record('admin',$scope->adminId,$merId,self::CLOSE,'merchant',(string)$merId,'success',
                        ['changed_fields'=>['closed_at','accept_orders'],'protected_employee_count'=>count($prepared['ids'])]);
                    return self::closed($merId,$now,false);
                });
            });
        }catch(AuthException|HttpException $error){throw $error;}
        catch(Throwable $error){throw new AuthUnavailableException();}
    }

    private static function target(PlatformScope $scope): int
    {
        if($scope->targetMerId===null||$scope->targetMerId<1||$scope->targetMerId>4294967295)throw new AuthUnavailableException();
        return $scope->targetMerId;
    }

    private static function closed(int $merId,int $closedAt,bool $alreadyClosed): array
    {
        if($closedAt<1||$closedAt>4294967295)throw new AuthUnavailableException();
        return ['schema'=>1,'scope'=>'MERCHANT_CLOSED','mer_id'=>$merId,'closed_at'=>$closedAt,'already_closed'=>$alreadyClosed];
    }
}
