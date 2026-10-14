<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\Request;
use app\dao\marketplace\scope\PlatformScope;
use app\model\system\SystemMenus;
use app\model\system\admin\SystemAdmin;
use app\model\system\admin\SystemRole;
use app\services\system\admin\SystemRoleServices;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use think\exception\HttpException;
use think\Model;

/** Current administrator grants for the finite existing product writers. */
final class LegacyProductAccessServices
{
    public const ACTIONS = [
        'legacy-product-save'=>['POST','product/product/<id>'],
        'legacy-product-show'=>['PUT','product/product/set_show/<id>/<is_show>'],
        'legacy-product-batch-show'=>['PUT','product/product/product_show'],
        'legacy-product-batch-hide'=>['PUT','product/product/product_unshow'],
        'legacy-product-delete'=>['DELETE','product/product/<id>'],
        'legacy-product-batch-delete'=>['POST','product/product/batch_delete'],
        'legacy-product-batch-recover'=>['POST','product/product/batch_recover'],
        'legacy-product-batch-setting'=>['POST','product/batch/setting'],
        'legacy-product-other-save'=>['POST','product/other_save/<id>/<type>'],
        'legacy-product-import'=>['POST','product/product_import'],
        'legacy-product-full-delete'=>['DELETE','product/full_del/<id>'],
        'legacy-product-recycle'=>['GET','system/clear/<type>'],
        'legacy-product-reply-write'=>['PUT','product/reply/set_reply/<id>'],
        'legacy-product-reply-delete'=>['DELETE','product/reply/<id>'],
        'legacy-product-reply-create'=>['POST','product/reply/save_fictitious_reply'],
        'legacy-product-reply-review'=>['PUT','product/reply/set_status/<id>/<status>'],
        'legacy-product-reply-batch-review'=>['POST','product/reply/batch_set_status'],
    ];

    public static function rule(string $rule): string
    {
        // Compare actual rule and menu using the existing whitespace/case convention.
        $rule=trim(strtolower(str_replace(' ','',$rule)));
        return preg_replace('/(?<=\/):([a-z_][a-z0-9_]*)/', '<$1>', $rule) ?? '';
    }
    public static function isWriter(\think\Request $request): bool
    {
        try {
            if(app()->http->getName()!=='adminapi')return false;
            $action=$request->rule()->getOption('legacy_product_write');
            if(!is_string($action)||!isset(self::ACTIONS[$action]))return false;
            if($action==='legacy-product-recycle'&&$request->route('type')!=='recycle')return false;
            return strtoupper($request->method())===self::ACTIONS[$action][0]
                &&self::rule((string)$request->rule()->getRule())===self::ACTIONS[$action][1];
        }catch(\Throwable $error){return false;}
    }
    public function scopeForRequest(Request $request): PlatformScope
    {
        try{
            $action=$this->action($request);$identity=$request->adminInfo();$id=$request->adminId();
            if($request->isAdminLogin()!==true||!is_array($identity)
                ||(string)($identity['id']??'')!==(string)$id)throw new AuthSessionException();
            $id=self::positive($id);$roles=$this->current($id,$action);
            CacheService::delete('all_auth');
            CacheService::delete(md5(SystemRoleServices::ADMIN_RULES_LEVEL.'_2_'.implode('_',$roles)));
            return new PlatformScope($id,$action,null);
        }catch(AuthException|HttpException $error){throw $error;}
        catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    public function authorizeScope(PlatformScope $scope): void
    {
        try{
            $request=app()->request;
            if(!$request instanceof Request||$request->platformScope()!==$scope
                ||$scope->targetMerId!==null||(string)$request->adminId()!==(string)$scope->adminId
                ||$this->action($request)!==$scope->permission)throw new HttpException(403,'没有权限访问');
            $this->current($scope->adminId,$scope->permission);
        }catch(AuthException|HttpException $error){throw $error;}
        catch(\Throwable $error){throw new AuthUnavailableException();}
    }
    private function action(Request $request): string
    {
        if(!self::isWriter($request))throw new HttpException(403,'没有权限访问');
        return $request->rule()->getOption('legacy_product_write');
    }
    private function current(int $id,string $action): array
    {
        $admin=self::raw((new SystemAdmin())->db()->where('id',$id)
            ->field(['id','status','is_del','level','roles'])->find());
        if($admin===null||(int)$admin['status']!==1||(int)$admin['is_del']!==0)throw new AuthSessionException();
        $roles=is_string($admin['roles'])?explode(',',$admin['roles']):$admin['roles'];
        if(!is_array($roles)||!array_is_list($roles))throw new AuthUnavailableException();
        foreach($roles as$role)if(!is_string($role)&&!is_int($role))throw new AuthUnavailableException();
        // Preserve the existing highest administrator's finite writer access.
        if((int)$admin['level']===0)return $roles;
        $ids=self::ids($roles);if(!$ids)throw new HttpException(403,'没有权限访问');
        $grants=[];foreach((new SystemRole())->db()->whereIn('id',$ids)->where('status',1)->column('rules')as$rules)
            $grants=array_merge($grants,self::ids($rules));
        if(!$grants)throw new HttpException(403,'没有权限访问');
        [$method,$rule]=self::ACTIONS[$action];
        foreach((new SystemMenus())->db()->whereIn('id',array_values(array_unique($grants)))
            ->where('auth_type',2)->where('is_del',0)->field(['id','api_url','methods'])->select()as$model){
            $menu=self::raw($model);
            if($menu!==null&&strtoupper(trim($menu['methods']))===$method&&self::rule($menu['api_url'])===$rule)return $roles;
        }
        throw new HttpException(403,'没有权限访问');
    }
    private static function positive(mixed $value): int
    {
        if(is_string($value)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)===1)$value=(int)$value;
        if(!is_int($value)||$value<1||$value>4294967295)throw new AuthSessionException();return $value;
    }
    private static function ids(mixed $value): array
    {
        if(is_string($value))$value=$value===''?[]:explode(',',$value);
        if(!is_array($value)||!array_is_list($value))throw new AuthUnavailableException();$out=[];
        foreach($value as$id){if($id===''||$id==='0'||$id===0)continue;
            if(is_string($id)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$id)===1)$id=(int)$id;
            if(!is_int($id)||$id<1||$id>4294967295)throw new AuthUnavailableException();$out[]=$id;}
        return array_values(array_unique($out));
    }
    private static function raw(array|Model|null $value): ?array
    { $value=$value instanceof Model?$value->getData():$value;return is_array($value)&&$value!==[]?$value:null; }
}
