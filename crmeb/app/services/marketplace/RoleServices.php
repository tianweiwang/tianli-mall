<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\dao\marketplace\EmployeeDao;
use app\dao\marketplace\RoleDao;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use crmeb\services\auth\AccountChangeGuard;
use think\exception\HttpException;
use UnexpectedValueException;

/** Manage only the roles belonging to the verified request's merchant. */
final class RoleServices
{
    private const PERMISSION = 'merchant.role.manage';
    private const APP = 'merchantapi';

    public function __construct(
        private EmployeeAuthServices $authentication,
        private RoleDao $roles,
        private EmployeeDao $employees
    ) {}

    public function listing(MerchantContext $ctx, array $query = []): array
    {
        $ctx->require(self::PERMISSION);
        try {
            $this->authentication->authorizeAction($ctx, self::PERMISSION);
            $query = MerchantManagementInput::body($query, ['page', 'limit']);
            $page = MerchantManagementInput::integer($query['page'] ?? 1, 1, 2147483647, true);
            $limit = MerchantManagementInput::integer($query['limit'] ?? 20, 1, 100, true);
            $result = $this->roles->listForManagement($ctx, $page, $limit);
            foreach ($result['list'] as $row) {
                self::assertStoredPermissions($row);
            }
            return $result;
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function create(MerchantContext $ctx, array $body): array
    {
        $ctx->require(self::PERMISSION);
        try {
            $this->authentication->authorizeAction($ctx, self::PERMISSION);
            $body = MerchantManagementInput::body($body, ['name', 'permissions'], ['name', 'permissions']);
            $name = MerchantManagementInput::text($body['name'], 64, true, true);
            $permissions = MerchantManagementInput::permissions($body['permissions']);

            return AccountChangeGuard::runMany(self::APP, [], function () use ($ctx, $name, $permissions): array {
                // This obtains the shared merchant lock before any role access.
                $this->authentication->authorizeAction($ctx, self::PERMISSION, true);
                return MerchantManagementInput::write(function () use ($ctx, $name, $permissions): array {
                    if ($this->roles->nameExists($ctx, $name)) {
                        throw new HttpException(422, '角色名称已存在', null, [], 422);
                    }
                    $roleId = $this->roles->createForMerchant($ctx, $name, $permissions, time());
                    $role = $this->roles->findForManagement($ctx, $roleId);
                    if ($role === null) {
                        throw new AuthUnavailableException();
                    }
                    self::assertStoredPermissions($role);
                    return $role;
                });
            });
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function update(MerchantContext $ctx, int $roleId, array $body): array
    {
        $ctx->require(self::PERMISSION);
        try {
            $this->authentication->authorizeAction($ctx, self::PERMISSION);
            $roleId = MerchantManagementInput::integer($roleId, 1, 4294967295);
            $body = MerchantManagementInput::body($body, ['name', 'permissions']);
            if ($body === []) {
                throw new HttpException(422, '请求参数不正确', null, [], 422);
            }
            $requested = [];
            if (array_key_exists('name', $body)) {
                $requested['name'] = MerchantManagementInput::text($body['name'], 64, true, true);
            }
            if (array_key_exists('permissions', $body)) {
                $requested['permissions'] = MerchantManagementInput::permissions($body['permissions']);
            }

            // This read determines the exact protected set; it cannot authorize
            // a write or replace the transaction's fresh actor/role checks.
            $before = $this->roles->findForManagement($ctx, $roleId);
            if ($before === null) {
                throw new MerchantNotFoundException();
            }
            self::assertStoredPermissions($before);
            $changes = [];
            if (array_key_exists('name', $requested) && $requested['name'] !== $before['name']) {
                $changes['name'] = $requested['name'];
            }
            $permissionsChanged = array_key_exists('permissions', $requested)
                && !self::samePermissions($before['permissions'], $requested['permissions']);
            if ($permissionsChanged) {
                $changes['permissions'] = $requested['permissions'];
            }
            $protectedIds = $permissionsChanged ? $this->employees->idsForRole($ctx, $roleId) : [];

            return AccountChangeGuard::runMany(self::APP, $protectedIds,
                function () use ($ctx, $roleId, $before, $changes, $permissionsChanged, $protectedIds): array {
                    // A protected actor is checked from current Db, not by
                    // attempting to authenticate the now-blocked old session.
                    $this->authentication->authorizeAction($ctx, self::PERMISSION, true);
                    return MerchantManagementInput::write(
                        function () use ($ctx, $roleId, $before, $changes, $permissionsChanged, $protectedIds): array {
                            $current = $this->roles->findForManagement($ctx, $roleId, true);
                            if ($current === null || $current !== $before) {
                                throw new AuthUnavailableException();
                            }
                            if ($permissionsChanged
                                && $this->employees->idsForRole($ctx, $roleId, true) !== $protectedIds) {
                                throw new AuthUnavailableException();
                            }
                            if ($changes === []) {
                                // Retain the exact old data, timestamps and versions.
                                return $current;
                            }
                            if (isset($changes['name'])
                                && $this->roles->nameExists($ctx, $changes['name'], $roleId)) {
                                throw new HttpException(422, '角色名称已存在', null, [], 422);
                            }
                            $now = time();
                            $affected = $this->roles->updateForManagement($ctx, $roleId, $changes, $now);
                            if ($affected < 0 || $affected > 1) {
                                throw new AuthUnavailableException();
                            }
                            $after = $this->roles->findForManagement($ctx, $roleId);
                            $expected = array_replace($current, $changes, ['update_time' => $now]);
                            // Observed state determines success, including a
                            // legitimate zero affected-row driver result.
                            if ($after === null || $after !== $expected) {
                                throw new AuthUnavailableException();
                            }
                            self::assertStoredPermissions($after);
                            return $after;
                        }
                    );
                }
            );
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    private static function assertStoredPermissions(array $role): void
    {
        if (!is_array($role['permissions'] ?? null)) {
            throw new UnexpectedValueException('Invalid role permission storage');
        }
        MerchantPermissions::validate($role['permissions']);
    }

    /** Permission order has no authorization effect and does not rotate sessions. */
    private static function samePermissions(array $before, array $requested): bool
    {
        $before = MerchantPermissions::validate($before);
        $requested = MerchantPermissions::validate($requested);
        sort($before, SORT_STRING);
        sort($requested, SORT_STRING);
        return $before === $requested;
    }
}
