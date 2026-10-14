<?php
declare(strict_types=1);
namespace app\services\marketplace;

use app\dao\marketplace\PlatformProductSubmissionDao;
use app\dao\marketplace\scope\PlatformScope;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\auth\AccountChangeGuard;
use think\exception\HttpException;
use think\exception\ValidateException;

/** A real platform submission grant advances draft/rejected to pending; it never approves a product. */
final class PlatformProductSubmissionServices
{
    public const ACTION = 'marketplace-products-submit-review';

    public function __construct(private PlatformProductSubmissionDao $products,private PlatformAccessServices $access,
        private AuditLogServices $audit) {}

    public function submitReview(PlatformScope $scope,int $id,array $body): array
    {
        $this->authorize($scope); MerchantManagementInput::integer($id,1,8388607,true);
        MerchantManagementInput::body($body,[]); $controlled = null;
        try {
            return AccountChangeGuard::runMany('adminapi',[],function()use($scope,$id,&$controlled):array{
                try {
                    $this->authorize($scope);
                    return MerchantManagementInput::write(function()use($scope,$id):array{
                        $before = $this->products->lockProduct($scope,$id);
                        $root = $before['root']; $status = self::storedInteger($root['review_status'],0,3);
                        if (self::storedInteger($root['is_del'],0,1) !== 0) throw new HttpException(404,'资源不存在');
                        // Match the actual merchant producer: already pending is a true no-op, including audit.
                        if ($status === 1) { $this->authorize($scope);return self::dto($root); }
                        if (!in_array($status,[0,3],true)) throw new HttpException(422,'商品当前状态不能提交审核');
                        $this->products->assertDefinition($before);
                        $owner = self::storedInteger($root['mer_id'],1,4294967295);
                        $auditBefore = $this->products->auditRows($scope,$id,$owner);
                        [$after,$fields] = $this->products->submit($scope,$id,$before);
                        $auditStart = time();
                        $this->audit->record('admin',$scope->adminId,$owner,self::ACTION,'product',(string)$id,'success',['changed_fields'=>$fields]);
                        $this->products->assertAudit($scope,$id,$owner,$auditBefore,$fields,$auditStart,time());
                        $this->authorize($scope);return self::dto($after['root']);
                    });
                } catch (HttpException $error) {
                    $status = $error->getStatusCode();if (!in_array($status,[401,403,404,422],true)) throw $error;
                    $controlled = $status;throw new ValidateException('平台商品提交请求未完成');
                }
            });
        } catch (ValidateException $error) {
            if ($controlled !== null) throw new HttpException($controlled,match($controlled){401=>'请登录',403=>'没有权限访问',404=>'资源不存在',422=>'商品当前状态或参数不能提交审核'});
            throw new AuthUnavailableException();
        } catch (AuthException|HttpException $error) { throw $error; }
        catch (\Throwable $error) { throw new AuthUnavailableException(); }
    }

    private function authorize(PlatformScope $scope): void
    {
        $this->access->authorizeScope($scope,self::ACTION);
        if ($scope->targetMerId !== null) throw new AuthUnavailableException();
    }
    private static function dto(array $root): array
    {
        return ['id'=>self::storedInteger($root['id'],1,8388607),'mer_id'=>self::storedInteger($root['mer_id'],1,4294967295),
            'review_status'=>self::storedInteger($root['review_status'],0,3),'is_show'=>self::storedInteger($root['is_show'],0,1)];
    }
    private static function storedInteger(mixed $value,int $minimum,int $maximum): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value) === 1) $value = (int)$value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) throw new AuthUnavailableException();return $value;
    }
}
