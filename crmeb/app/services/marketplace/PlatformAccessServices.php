<?php
declare(strict_types=1);

namespace app\services\marketplace;

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
        'marketplace-merchants-admin-employee' => ['POST', 'marketplace/merchants/<id>/admin-employee', true],
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
