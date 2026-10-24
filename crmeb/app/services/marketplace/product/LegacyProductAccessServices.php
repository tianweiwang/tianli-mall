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
        'legacy-product-copy-save'=>['POST','product/crawl/save'],
        'legacy-product-media-list'=>['GET','product/product/<product_id>/media'],
        'legacy-product-media-upload'=>['POST','product/product/<product_id>/media'],
        'legacy-product-media-video-upload'=>['POST','product/product/<product_id>/media/videos/upload'],
        'legacy-product-media-audio-upload'=>['POST','product/product/<product_id>/media/audio/upload'],
        'legacy-product-media-online-upload'=>['POST','product/product/<product_id>/media/online_upload'],
        'legacy-product-media-scan-issue'=>['POST','product/product/<product_id>/media/scan'],
        'legacy-product-media-scan-poll'=>['GET','product/product/<product_id>/media/scan/<lease_id>'],
        'legacy-product-media-scan-preview'=>['GET','product/product/<product_id>/media/scan/<lease_id>/items/<stage_id>/content'],
        'legacy-product-media-scan-claim'=>['POST','product/product/<product_id>/media/scan/<lease_id>/claim'],
        'legacy-product-media-scan-close'=>['DELETE','product/product/<product_id>/media/scan/<lease_id>'],
        'legacy-product-media-categories-list'=>['GET','product/product/<product_id>/media/categories'],
        'legacy-product-media-categories-create'=>['POST','product/product/<product_id>/media/categories'],
        'legacy-product-media-categories-update'=>['PUT','product/product/<product_id>/media/categories/<category_id>'],
        'legacy-product-media-categories-delete'=>['DELETE','product/product/<product_id>/media/categories/<category_id>'],
        'legacy-product-media-rename'=>['PUT','product/product/<product_id>/media/<att_id>/name'],
        'legacy-product-media-move'=>['PUT','product/product/<product_id>/media/move'],
        'legacy-product-media-delete'=>['POST','product/product/<product_id>/media/delete'],
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

    /** Finite media-only compatibility grants; each is ANDed with its own leaf and product save. */
    public const MEDIA_GRANTS = [
        'legacy-product-media-list'=>['GET','file/file'],
        'legacy-product-media-upload'=>['POST','file/upload/<upload_type?>'],
        'legacy-product-media-video-upload'=>['POST','file/upload/<upload_type?>'],
        'legacy-product-media-audio-upload'=>['POST','file/upload/<upload_type?>'],
        'legacy-product-media-online-upload'=>['POST','file/online_upload'],
        'legacy-product-media-scan-issue'=>['GET','file/scan_upload/qrcode'],
        'legacy-product-media-scan-poll'=>['GET','file/scan_upload/image/<scan_token>'],
        'legacy-product-media-scan-preview'=>['GET','file/scan_upload/image/<scan_token>'],
        'legacy-product-media-scan-claim'=>['PUT','file/file/do_move'],
        'legacy-product-media-scan-close'=>['DELETE','file/scan_upload/qrcode'],
        'legacy-product-media-categories-list'=>['GET','file/category'],
        'legacy-product-media-categories-create'=>['POST','file/category'],
        'legacy-product-media-categories-update'=>['PUT','file/category/<id>'],
        'legacy-product-media-categories-delete'=>['DELETE','file/category/<id>'],
        'legacy-product-media-rename'=>['PUT','file/file/update/<id>'],
        'legacy-product-media-move'=>['PUT','file/file/do_move'],
        'legacy-product-media-delete'=>['POST','file/file/delete'],
    ];

    /** Copy uses its actual leaf AND standard product save; it is not a media action. */
    public const COPY_GRANTS = [
        'legacy-product-copy-save'=>['POST','product/product/<id>'],
    ];

    /** Finite operations, never a caller-supplied authorization rule. */
    public const PRODUCT_SCAN_ACTIONS = [
        'issue'=>'legacy-product-media-scan-issue',
        'poll'=>'legacy-product-media-scan-poll',
        'preview'=>'legacy-product-media-scan-preview',
        'claim'=>'legacy-product-media-scan-claim',
        'close'=>'legacy-product-media-scan-close',
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
    /** Read current issuer grants without fabricating a request or scope. */
    public function authorizeProductScanIssuer(int $adminId,string $operation): void
    {
        if(!isset(self::PRODUCT_SCAN_ACTIONS[$operation]))throw new AuthUnavailableException();
        $this->current(self::positive($adminId),self::PRODUCT_SCAN_ACTIONS[$operation]);
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
        if(isset(self::MEDIA_GRANTS[$action])){
            // NULL is a schema-valid empty grant set only for these finite media actions.
            $mediaGrants=[];
            foreach((new SystemRole())->db()->whereIn('id',$ids)->where('status',1)->column('rules')as$rules)
                if($rules!==null)$mediaGrants=array_merge($mediaGrants,self::ids($rules));
            if(!$mediaGrants)throw new HttpException(403,'没有权限访问');
            // The three grants may belong to different current active roles of this actual administrator.
            $required=[self::ACTIONS[$action],
                self::MEDIA_GRANTS[$action],
                self::ACTIONS['legacy-product-save']];
            $found=array_fill(0,count($required),false);
            foreach((new SystemMenus())->db()->whereIn('id',array_values(array_unique($mediaGrants)))
                ->where('auth_type',2)->where('is_del',0)->field(['id','api_url','methods'])->select()as$model){
                $menu=self::raw($model);if($menu===null)continue;
                foreach($required as$index=>[$method,$rule])
                    if(strtoupper(trim($menu['methods']))===$method&&self::rule($menu['api_url'])===$rule)$found[$index]=true;
            }
            if(!in_array(false,$found,true))return $roles;
            throw new HttpException(403,'没有权限访问');
        }
        $grants=[];foreach((new SystemRole())->db()->whereIn('id',$ids)->where('status',1)->column('rules')as$rules)
            $grants=array_merge($grants,self::ids($rules));
        if(!$grants)throw new HttpException(403,'没有权限访问');
        if(isset(self::COPY_GRANTS[$action])){
            $required=[self::ACTIONS[$action],self::COPY_GRANTS[$action]];$found=array_fill(0,count($required),false);
            foreach((new SystemMenus())->db()->whereIn('id',array_values(array_unique($grants)))
                ->where('auth_type',2)->where('is_del',0)->field(['id','api_url','methods'])->select()as$model){
                $menu=self::raw($model);if($menu===null)continue;
                foreach($required as$index=>[$method,$rule])
                    if(strtoupper(trim($menu['methods']))===$method&&self::rule($menu['api_url'])===$rule)$found[$index]=true;
            }
            if(!in_array(false,$found,true))return $roles;
            throw new HttpException(403,'没有权限访问');
        }
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
