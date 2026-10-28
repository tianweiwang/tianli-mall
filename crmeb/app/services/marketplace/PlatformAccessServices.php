<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\Request;
use app\dao\marketplace\scope\PlatformScope;
use app\model\system\SystemMenus;
use app\model\system\admin\SystemAdmin;
use app\model\system\admin\SystemRole;
use app\services\system\admin\SystemRoleServices;
use app\services\marketplace\product\LegacyProductAccessServices;
use app\services\marketplace\product\LegacyProductWriteServices;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\CacheService;
use think\exception\HttpException;
use think\Model;

/** The request's verified administrator and current DB grants produce the scope. */
final class PlatformAccessServices
{
    public const ACTIONS = [
        'marketplace-merchants-list' => ['GET', 'marketplace/merchants', false],
        'marketplace-merchants-create' => ['POST', 'marketplace/merchants', false],
        'marketplace-merchants-read' => ['GET', 'marketplace/merchants/<id>', true],
        'marketplace-merchants-update' => ['PUT', 'marketplace/merchants/<id>', true],
        'marketplace-merchants-review' => ['PUT', 'marketplace/merchants/<id>/review', true],
        'marketplace-merchants-flags' => ['PUT', 'marketplace/merchants/<id>/flags', true],
        'marketplace-merchants-exit-precheck' => ['GET', 'marketplace/merchants/<id>/exit-precheck', true],
        'marketplace-merchants-close' => ['PUT', 'marketplace/merchants/<id>/close', true],
        'marketplace-merchants-admin-employee' => ['POST', 'marketplace/merchants/<id>/admin-employee', true],
        'marketplace-merchants-password-setup-reissue' => ['POST', 'marketplace/merchants/<id>/password-setup-reissue', true],
        'platform-assets-list' => ['GET', 'marketplace/platform-assets', false],
        'platform-assets-upload' => ['POST', 'marketplace/platform-assets', false],
        'platform-asset-categories-list' => ['GET', 'marketplace/platform-asset-categories', false],
        'marketplace-products-import-platform-image' => ['POST', 'marketplace/products/<id>/import-platform-image', false],
        'marketplace-products-list' => ['GET', 'marketplace/products', false],
        'marketplace-products-review' => ['PUT', 'marketplace/products/<id>/review', false],
        'marketplace-products-off-shelf' => ['PUT', 'marketplace/products/<id>/off-shelf', false],
        'marketplace-products-stock-adjust' => ['POST', 'marketplace/products/<id>/stock', false],
        'marketplace-products-submit-review' => ['PUT', 'marketplace/products/<id>/submit-review', false],
        'marketplace-reviews-list' => ['GET', 'marketplace/reviews', false],
        'marketplace-reviews-read' => ['GET', 'marketplace/reviews/<id>', false],
        'marketplace-reviews-moderate' => ['PUT', 'marketplace/reviews/<id>/review', false],
        'marketplace-aftersales-list' => ['GET', 'marketplace/aftersales', false],
        'marketplace-aftersales-read' => ['GET', 'marketplace/aftersales/<id>', false],
        'marketplace-aftersales-approve' => ['POST', 'marketplace/aftersales/<id>/approve', false],
        'marketplace-aftersales-reject' => ['POST', 'marketplace/aftersales/<id>/reject', false],
        'marketplace-aftersales-return-accept' => ['POST', 'marketplace/aftersales/<id>/return-accept', false],
        'marketplace-aftersale-files-ticket' => ['POST', 'marketplace/aftersale-files/<file_id>/read-ticket', false],
        'marketplace-aftersale-files-read' => ['GET', 'marketplace/aftersale-files/<file_id>/content', false],
    ];

    public static function isMarketplace(\think\Request $request): bool
    {
        try {
            return app()->http->getName() === 'adminapi'
                && $request->rule()->getOption('marketplace_api') === true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function scopeForRequest(Request $request, string $permission, ?int $merId): PlatformScope
    {
        try {
            self::assertRoute($request, $permission);
            $targeted = self::ACTIONS[$permission][2];
            if (($targeted && ($merId === null || $merId <= 0 || $merId > 4294967295))
                || (!$targeted && $merId !== null)) throw new HttpException(422, '请求参数不正确');
            $identity = $request->adminInfo();
            $adminId = $request->adminId();
            if (!is_array($identity) || (int)($identity['id'] ?? 0) <= 0
                || (int)$adminId !== (int)$identity['id']) throw new AuthSessionException();
            $roles = $this->authorizeCurrent((int)$adminId, $permission);
            // Delete only actual authorization cache keys after successful authorization.
            CacheService::delete('all_auth');
            CacheService::delete(md5(SystemRoleServices::ADMIN_RULES_LEVEL . '_2_' . implode('_', $roles)));
            return new PlatformScope((int)$adminId, $permission, $merId);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function authorizeScope(PlatformScope $scope, string $permission): void
    {
        try {
            $request = app()->request;
            if (!$request instanceof Request) throw new AuthSessionException();
            self::assertRoute($request, $permission);
            if ($request->platformScope() !== $scope || $scope->permission !== $permission
                || (int)$request->adminId() !== $scope->adminId) throw new HttpException(403, '没有权限访问');
            $this->authorizeCurrent($scope->adminId, $permission);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    /** Add the actual independent stock grant to this real legacy save scope, without constructing a stock request. */
    public function additionalStockGrant(PlatformScope $scope): void
    {
        try {
            LegacyProductWriteServices::transaction();
            if (LegacyProductWriteServices::rawScope() !== $scope || $scope->targetMerId !== null
                || !in_array($scope->permission,['legacy-product-save','legacy-product-import'],true)) throw new HttpException(403, '没有权限访问');
            app()->make(LegacyProductAccessServices::class)->authorizeScope($scope);
            $this->authorizeCurrent($scope->adminId, 'marketplace-products-stock-adjust');
        } catch (AuthException|HttpException $error) { throw $error; }
        catch (\Throwable $error) { throw new AuthUnavailableException(); }
    }

    /** Recompute only these three action hints from this actual list/detail scope and current DB grants. */
    public function aftersaleReadActions(PlatformScope $scope): array
    {
        try {
            if ($scope->targetMerId !== null || !in_array($scope->permission,
                ['marketplace-aftersales-list','marketplace-aftersales-read'],true)) throw new HttpException(403,'没有权限访问');
            $this->authorizeScope($scope,$scope->permission);
            $out=[];
            foreach (['approve'=>'marketplace-aftersales-approve','reject'=>'marketplace-aftersales-reject',
                'accept_return'=>'marketplace-aftersales-return-accept'] as $key=>$permission) {
                try { $this->authorizeCurrent($scope->adminId,$permission); $out[$key]=true; }
                catch (HttpException $error) { if ($error->getStatusCode() !== 403) throw $error; $out[$key]=false; }
            }
            $this->authorizeScope($scope,$scope->permission);
            return $out;
        } catch (AuthException|HttpException $error) { throw $error; }
        catch (\Throwable $error) { throw new AuthUnavailableException(); }
    }

    /** The source-read grant is additional to this actual import leaf + file + product-save grants. */
    public function additionalPlatformAssetReadGrant(PlatformScope $scope): void
    {
        try {
            if($scope->permission!=='marketplace-products-import-platform-image'||$scope->targetMerId!==null)throw new HttpException(403,'没有权限访问');
            $this->authorizeScope($scope,$scope->permission);
            app()->make(LegacyProductAccessServices::class)->authorizeScope($scope);
            $this->authorizeCurrent($scope->adminId,'platform-assets-list');
        }catch(AuthException|HttpException $error){throw $error;}
        catch(\Throwable $error){throw new AuthUnavailableException();}
    }

    private static function assertRoute(Request $request, string $permission): void
    {
        if (!isset(self::ACTIONS[$permission]) || !self::isMarketplace($request)
            || $request->rule()->getOption('marketplace_permission') !== $permission
            || strtoupper($request->method()) !== self::ACTIONS[$permission][0]
            || trim(strtolower((string)$request->rule()->getRule())) !== self::ACTIONS[$permission][1]) {
            throw new HttpException(403, '没有权限访问');
        }
    }

    /** Do not consult a previously cached menu/role decision. No password is read here. */
    private function authorizeCurrent(int $adminId, string $permission): array
    {
        $admin = self::data((new SystemAdmin())->db()->where('id', '=', $adminId)
            ->field(['id', 'status', 'is_del', 'level', 'roles'])->find());
        if ($admin === null || (int)$admin['status'] !== 1 || (int)$admin['is_del'] !== 0) {
            throw new AuthSessionException();
        }
        $cacheRoles = is_string($admin['roles']) ? explode(',', $admin['roles']) : $admin['roles'];
        if (!is_array($cacheRoles) || !array_is_list($cacheRoles)) throw new AuthUnavailableException();
        foreach ($cacheRoles as $role) {
            if (!is_string($role) && !is_int($role)) throw new AuthUnavailableException();
        }
        [$method, $rule] = self::ACTIONS[$permission];
        $query = (new SystemMenus())->db()->where('unique_auth', '=', $permission)
            ->where('auth_type', '=', 2)->where('is_del', '=', 0);
        if ((int)(clone $query)->count() !== 1) throw new HttpException(403, '没有权限访问');
        $menu = self::data($query->field(['id', 'api_url', 'methods'])->find());
        if ($menu === null || trim(strtolower($menu['api_url'])) !== $rule
            || strtoupper(trim($menu['methods'])) !== $method) throw new HttpException(403, '没有权限访问');
        // Legacy highest administrators can have an empty or sentinel role field.
        if ((int)$admin['level'] === 0) return $cacheRoles;
        $roles = self::ids($cacheRoles);
        if ($roles === []) throw new HttpException(403, '没有权限访问');
        $rows = (new SystemRole())->db()->whereIn('id', $roles)->where('status', '=', 1)->column('rules');
        foreach ($rows as $rules) {
            // A legal NULL role has no grants; another enabled role may still grant this leaf.
            if ($rules === null) continue;
            if (in_array((int)$menu['id'], self::ids($rules), true)) return $cacheRoles;
        }
        throw new HttpException(403, '没有权限访问');
    }

    private static function ids(mixed $value): array
    {
        if (is_string($value)) $value = $value === '' ? [] : explode(',', $value);
        if (!is_array($value) || !array_is_list($value)) throw new AuthUnavailableException();
        $ids = [];
        foreach ($value as $id) {
            if ($id === '' || $id === '0' || $id === 0) continue;
            if ((!is_int($id) && (!is_string($id) || preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1))
                || (int)$id <= 0 || (int)$id > 4294967295) throw new AuthUnavailableException();
            $ids[] = (int)$id;
        }
        return array_values(array_unique($ids));
    }

    private static function data(array|Model|null $value): ?array
    {
        $value = $value instanceof Model ? $value->getData() : $value;
        return is_array($value) && $value !== [] ? $value : null;
    }
}
