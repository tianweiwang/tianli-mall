<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\dao\marketplace\EmployeeDao;
use app\dao\marketplace\MerchantDao;
use app\dao\marketplace\PasswordSetupDao;
use app\dao\marketplace\RoleDao;
use app\dao\marketplace\scope\PlatformScope;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use crmeb\services\auth\AccountChangeGuard;
use think\exception\HttpException;

/** Platform actions receive the actual request's authorized scope explicitly. */
final class MerchantAdminServices
{
    private const APP = 'merchantapi';
    private const LIST = 'marketplace-merchants-list';
    private const CREATE = 'marketplace-merchants-create';
    private const READ = 'marketplace-merchants-read';
    private const UPDATE = 'marketplace-merchants-update';
    private const REVIEW = 'marketplace-merchants-review';
    private const FLAGS = 'marketplace-merchants-flags';
    private const ADMIN_EMPLOYEE = 'marketplace-merchants-admin-employee';
    private const PROFILE_FIELDS = ['name', 'logo', 'after_sale_contact', 'shipping_note'];

    public function __construct(
        private MerchantDao $merchants,
        private RoleDao $roles,
        private EmployeeDao $employees,
        private PasswordSetupDao $setups,
        private PlatformAccessServices $access,
        private AuditLogServices $audit
    ) {}

    public function listing(PlatformScope $scope, array $query = []): array
    {
        try {
            $this->access->authorizeScope($scope, self::LIST);
            self::requireGlobal($scope);
            $query = MerchantManagementInput::body($query, ['page', 'limit', 'review_status']);
            $page = MerchantManagementInput::integer($query['page'] ?? 1, 1, 4294967295, true);
            $limit = MerchantManagementInput::integer($query['limit'] ?? 20, 1, 100, true);
            $review = array_key_exists('review_status', $query)
                ? MerchantManagementInput::integer($query['review_status'], 0, 2, true) : null;
            return AccountChangeGuard::runMany(self::APP, [], function () use ($scope, $page, $limit, $review): array {
                $this->access->authorizeScope($scope, self::LIST);
                return MerchantManagementInput::write(function () use ($scope, $page, $limit, $review): array {
                    $this->audit->record('admin', $scope->adminId, null, self::LIST, 'merchant', 'global', 'success', []);
                    return $this->merchants->listForPlatform($scope, $page, $limit, $review);
                });
            });
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function read(PlatformScope $scope): array
    {
        try {
            $this->access->authorizeScope($scope, self::READ);
            $merId = self::target($scope);
            return AccountChangeGuard::runMany(self::APP, [], function () use ($scope, $merId): array {
                $this->access->authorizeScope($scope, self::READ);
                return MerchantManagementInput::write(function () use ($scope, $merId): array {
                    $this->audit->record('admin', $scope->adminId, null, self::READ, 'merchant', (string)$merId, 'success', []);
                    $merchant = $this->merchants->findForPlatform($scope);
                    if ($merchant === null) {
                        throw new MerchantNotFoundException();
                    }
                    return $merchant;
                });
            });
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function create(PlatformScope $scope, array $body): array
    {
        try {
            $this->access->authorizeScope($scope, self::CREATE);
            self::requireGlobal($scope);
            $body = MerchantManagementInput::body($body, self::PROFILE_FIELDS, ['name']);
            $fields = [
                'name' => MerchantManagementInput::text($body['name'], 128, true, true),
                'logo' => array_key_exists('logo', $body) ? MerchantManagementInput::text($body['logo'], 512) : '',
                'after_sale_contact' => array_key_exists('after_sale_contact', $body)
                    ? MerchantManagementInput::text($body['after_sale_contact'], 255) : '',
                'shipping_note' => array_key_exists('shipping_note', $body)
                    ? MerchantManagementInput::text($body['shipping_note'], 1000) : '',
            ];
            return AccountChangeGuard::runMany(self::APP, [], function () use ($scope, $fields): array {
                $this->access->authorizeScope($scope, self::CREATE);
                return MerchantManagementInput::write(function () use ($scope, $fields): array {
                    $now = time();
                    $merId = $this->merchants->createForPlatform($scope, $fields, $now);
                    $merchant = $this->merchants->lockCreatedForPlatform($scope, $merId);
                    $expected = ['mer_id' => $merId] + $fields + [
                        'review_status' => 0, 'accept_orders' => 0, 'fulfillment_enabled' => 1,
                        'security_locked' => 0, 'closed_at' => null, 'add_time' => $now, 'update_time' => $now,
                    ];
                    if ($merchant === null || $merchant !== $expected) {
                        throw new AuthUnavailableException();
                    }
                    // These are business-owned roles of the real new merchant,
                    // never global seeds or a fabricated employee context.
                    $this->roles->createForPlatform($scope, $merId, '管理员', MerchantPermissions::BUILTIN_ADMIN, $now);
                    $this->roles->createForPlatform($scope, $merId, '运营', MerchantPermissions::BUILTIN_OPERATOR, $now);
                    $this->audit->record('admin', $scope->adminId, $merId, self::CREATE, 'merchant', (string)$merId,
                        'success', ['changed_fields' => [
                            'name', 'logo', 'after_sale_contact', 'shipping_note', 'review_status',
                            'accept_orders', 'fulfillment_enabled', 'security_locked', 'closed_at',
                        ], 'protected_employee_count' => 0]);
                    return $merchant;
                });
            });
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function update(PlatformScope $scope, array $body): array
    {
        try {
            $this->access->authorizeScope($scope, self::UPDATE);
            self::target($scope);
            $body = MerchantManagementInput::body($body, self::PROFILE_FIELDS);
            self::requireBody($body);
            $fields = [];
            foreach (self::PROFILE_FIELDS as $field) {
                if (!array_key_exists($field, $body)) {
                    continue;
                }
                $max = match ($field) {
                    'name' => 128, 'logo' => 512, 'after_sale_contact' => 255, 'shipping_note' => 1000,
                };
                $fields[$field] = MerchantManagementInput::text($body[$field], $max, $field === 'name', $field === 'name');
            }
            return $this->modify($scope, self::UPDATE, $fields);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function review(PlatformScope $scope, array $body): array
    {
        try {
            $this->access->authorizeScope($scope, self::REVIEW);
            self::target($scope);
            $body = MerchantManagementInput::body($body, ['review_status'], ['review_status']);
            return $this->modify($scope, self::REVIEW,
                ['review_status' => MerchantManagementInput::integer($body['review_status'], 0, 2)]);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function flags(PlatformScope $scope, array $body): array
    {
        try {
            $this->access->authorizeScope($scope, self::FLAGS);
            self::target($scope);
            $allowed = ['accept_orders', 'fulfillment_enabled', 'security_locked'];
            $body = MerchantManagementInput::body($body, $allowed);
            self::requireBody($body);
            $fields = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) {
                    $fields[$field] = MerchantManagementInput::integer($body[$field], 0, 1);
                }
            }
            return $this->modify($scope, self::FLAGS, $fields);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function createAdminEmployee(PlatformScope $scope, array $body): array
    {
        try {
            $this->access->authorizeScope($scope, self::ADMIN_EMPLOYEE);
            $merId = self::target($scope);
            $body = MerchantManagementInput::body($body, ['account', 'name', 'avatar'], ['account']);
            if (!is_string($body['account'])) {
                throw new HttpException(422, '请求参数不正确', null, [], 422);
            }
            $account = EmployeeAuthServices::normalizeAccount($body['account']);
            $name = array_key_exists('name', $body) ? MerchantManagementInput::text($body['name'], 64) : '';
            $avatar = array_key_exists('avatar', $body) ? MerchantManagementInput::text($body['avatar'], 512) : '';
            return AccountChangeGuard::runMany(self::APP, [], function () use ($scope, $merId, $account, $name, $avatar): array {
                return MerchantManagementInput::write(function () use ($scope, $merId, $account, $name, $avatar): array {
                    $merchant = $this->merchants->findForPlatform($scope, true);
                    $this->access->authorizeScope($scope, self::ADMIN_EMPLOYEE);
                    if ($merchant === null) {
                        throw new MerchantNotFoundException();
                    }
                    if ($merchant['closed_at'] !== null) {
                        throw new HttpException(422, '商家已关闭', null, [], 422);
                    }
                    if ($this->employees->accountExists($account)) {
                        throw new HttpException(422, '数据已存在', null, [], 422);
                    }
                    $now = time();
                    $roleId = $this->administratorRole($scope, $merId, $now);
                    $employeeId = $this->employees->createForPlatform($scope,
                        ['account' => $account, 'role_id' => $roleId, 'name' => $name, 'avatar' => $avatar], $now);
                    $capability = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $this->setups->createForPlatform($scope, $employeeId, hash('sha256', $capability, true), $now);
                    $employee = $this->employees->findForPlatform($scope, $employeeId);
                    $expected = ['id' => $employeeId, 'mer_id' => $merId, 'role_id' => $roleId,
                        'account' => $account, 'name' => $name, 'avatar' => $avatar, 'status' => 1,
                        'add_time' => $now, 'update_time' => $now];
                    if ($employee === null || $employee !== $expected) {
                        throw new AuthUnavailableException();
                    }
                    $this->audit->record('admin', $scope->adminId, $merId, self::ADMIN_EMPLOYEE,
                        'employee', (string)$employeeId, 'success', [
                            'changed_fields' => ['role_id', 'account', 'name', 'avatar', 'status'],
                            'employee_id' => $employeeId, 'role_id' => $roleId, 'protected_employee_count' => 0,
                        ]);
                    // The guard returns this delivery only after the real commit.
                    return ['employee' => $employee, 'password_setup' => [
                        'endpoint' => '/merchantapi/password/setup', 'token' => $capability,
                        'expires_at' => $now + 86400,
                    ]];
                });
            });
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    private function modify(PlatformScope $scope, string $permission, array $proposed): array
    {
        $merId = self::target($scope);
        $before = $this->merchants->findForPlatform($scope);
        if ($before === null) {
            throw new MerchantNotFoundException();
        }
        $changes = [];
        foreach ($proposed as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = $value;
            }
        }
        $eligibilityChanges = array_intersect(array_keys($changes), ['review_status', 'security_locked']) !== [];
        $protectedIds = $eligibilityChanges ? $this->employees->idsForPlatformMerchant($scope) : [];
        return AccountChangeGuard::runMany(self::APP, $protectedIds,
            function () use ($scope, $permission, $merId, $before, $changes, $eligibilityChanges, $protectedIds): array {
                return MerchantManagementInput::write(
                    function () use ($scope, $permission, $merId, $before, $changes, $eligibilityChanges, $protectedIds): array {
                        // All target writers take this same merchant row first.
                        $current = $this->merchants->findForPlatform($scope, true);
                        $this->access->authorizeScope($scope, $permission);
                        if ($current === null || $current !== $before) {
                            throw new AuthUnavailableException();
                        }
                        if ($eligibilityChanges
                            && $this->employees->idsForPlatformMerchant($scope, true) !== $protectedIds) {
                            throw new AuthUnavailableException();
                        }
                        $after = $current;
                        if ($changes !== []) {
                            $now = time();
                            $affected = $this->merchants->updateForPlatform($scope, $changes, $now);
                            if ($affected < 0 || $affected > 1) {
                                throw new AuthUnavailableException();
                            }
                            $after = $this->merchants->findForPlatform($scope);
                            $expected = array_replace($current, $changes, ['update_time' => $now]);
                            if ($after === null || $after !== $expected) {
                                throw new AuthUnavailableException();
                            }
                        }
                        // A valid no-op retains row/time/version but is still
                        // one successful, explicitly attributed platform write.
                        $this->audit->record('admin', $scope->adminId, $merId, $permission,
                            'merchant', (string)$merId, 'success', [
                                'changed_fields' => array_keys($changes),
                                'protected_employee_count' => count($protectedIds),
                            ]);
                        return $after;
                    }
                );
            }
        );
    }

    private function administratorRole(PlatformScope $scope, int $merId, int $now): int
    {
        $roles = $this->roles->listForPlatform($scope, $merId, true);
        $wanted = MerchantPermissions::BUILTIN_ADMIN;
        sort($wanted, SORT_STRING);
        $names = [];
        foreach ($roles as $role) {
            $names[$role['name']] = true;
            $permissions = MerchantPermissions::validate($role['permissions']);
            sort($permissions, SORT_STRING);
            if ($permissions === $wanted) {
                // listForPlatform is ordered by actual role ID ascending.
                return $role['id'];
            }
        }
        $index = 1;
        do {
            $name = $index === 1 ? '管理员' : '管理员 ' . $index;
            $index++;
        } while (isset($names[$name]));
        return $this->roles->createForPlatform($scope, $merId, $name, MerchantPermissions::BUILTIN_ADMIN, $now);
    }

    private static function target(PlatformScope $scope): int
    {
        if ($scope->targetMerId === null || $scope->targetMerId > 4294967295) {
            throw new AuthUnavailableException();
        }
        return $scope->targetMerId;
    }

    private static function requireGlobal(PlatformScope $scope): void
    {
        if ($scope->targetMerId !== null) {
            throw new AuthUnavailableException();
        }
    }

    private static function requireBody(array $body): void
    {
        if ($body === []) {
            throw new HttpException(422, '请求参数不正确', null, [], 422);
        }
    }
}
