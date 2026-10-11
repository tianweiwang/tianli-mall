<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\dao\marketplace\EmployeeDao;
use app\dao\marketplace\PasswordSetupDao;
use app\dao\marketplace\RoleDao;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\exceptions\MerchantNotFoundException;
use crmeb\services\auth\AccountChangeGuard;
use think\exception\HttpException;

/** Employee management never retains a request identity or accepts an owner selector. */
final class EmployeeServices
{
    private const APP = 'merchantapi';
    private const PERMISSION = 'merchant.employee.manage';
    private const MAX_ID = 4294967295;

    public function __construct(
        private EmployeeDao $employees,
        private RoleDao $roles,
        private PasswordSetupDao $setups,
        private EmployeeAuthServices $auth
    ) {}

    public function listing(MerchantContext $ctx, array $query = []): array
    {
        try {
            $this->auth->authorizeAction($ctx, self::PERMISSION);
            $query = MerchantManagementInput::body($query, ['page', 'limit', 'status', 'role_id']);
            $page = MerchantManagementInput::integer($query['page'] ?? 1, 1, self::MAX_ID, true);
            $limit = MerchantManagementInput::integer($query['limit'] ?? 20, 1, 100, true);
            $status = array_key_exists('status', $query)
                ? MerchantManagementInput::integer($query['status'], 0, 1, true) : null;
            $roleId = array_key_exists('role_id', $query)
                ? MerchantManagementInput::integer($query['role_id'], 1, self::MAX_ID, true) : null;
            return $this->employees->listForManagement($ctx, $page, $limit, $status, $roleId);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function create(MerchantContext $ctx, array $body): array
    {
        try {
            $this->auth->authorizeAction($ctx, self::PERMISSION);
            $body = MerchantManagementInput::body($body, ['account', 'role_id', 'name', 'avatar'], ['account', 'role_id']);
            $fields = [
                'account' => self::account($body['account']),
                'role_id' => MerchantManagementInput::integer($body['role_id'], 1, self::MAX_ID),
                'name' => array_key_exists('name', $body) ? MerchantManagementInput::text($body['name'], 64) : '',
                'avatar' => array_key_exists('avatar', $body) ? MerchantManagementInput::text($body['avatar'], 512) : '',
            ];
            return AccountChangeGuard::runMany(self::APP, [], function () use ($ctx, $fields): array {
                return MerchantManagementInput::write(function () use ($ctx, $fields): array {
                    $this->auth->authorizeAction($ctx, self::PERMISSION, true);
                    if ($this->roles->findForManagement($ctx, $fields['role_id'], true) === null) {
                        throw new MerchantNotFoundException();
                    }
                    if ($this->employees->accountExists($fields['account'])) {
                        throw self::invalidInput();
                    }
                    $now = time();
                    $capability = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $employeeId = $this->employees->createForMerchant($ctx, $fields, $now);
                    $this->setups->createForEmployee($ctx, $employeeId, hash('sha256', $capability, true), $now);
                    $employee = $this->employees->findForManagement($ctx, $employeeId);
                    if ($employee === null) {
                        throw new AuthUnavailableException();
                    }
                    // The guard returns this value only after the actual DB commit.
                    return [
                        'employee' => $employee,
                        'password_setup' => [
                            'endpoint' => '/merchantapi/password/setup',
                            'token' => $capability,
                            'expires_at' => $now + 86400,
                        ],
                    ];
                });
            });
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function update(MerchantContext $ctx, int $employeeId, array $body): array
    {
        try {
            $this->auth->authorizeAction($ctx, self::PERMISSION);
            $employeeId = MerchantManagementInput::integer($employeeId, 1, self::MAX_ID);
            $body = MerchantManagementInput::body($body, ['account', 'role_id', 'name', 'avatar']);
            if ($body === []) {
                throw self::invalidInput();
            }
            $fields = [];
            if (array_key_exists('account', $body)) {
                $fields['account'] = self::account($body['account']);
            }
            if (array_key_exists('role_id', $body)) {
                $fields['role_id'] = MerchantManagementInput::integer($body['role_id'], 1, self::MAX_ID);
            }
            if (array_key_exists('name', $body)) {
                $fields['name'] = MerchantManagementInput::text($body['name'], 64);
            }
            if (array_key_exists('avatar', $body)) {
                $fields['avatar'] = MerchantManagementInput::text($body['avatar'], 512);
            }
            return $this->modify($ctx, $employeeId, $fields);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    public function setStatus(MerchantContext $ctx, int $employeeId, array $body): array
    {
        try {
            $this->auth->authorizeAction($ctx, self::PERMISSION);
            $employeeId = MerchantManagementInput::integer($employeeId, 1, self::MAX_ID);
            $body = MerchantManagementInput::body($body, ['status'], ['status']);
            return $this->modify($ctx, $employeeId,
                ['status' => MerchantManagementInput::integer($body['status'], 0, 1)]);
        } catch (AuthException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new AuthUnavailableException();
        }
    }

    private function modify(MerchantContext $ctx, int $employeeId, array $proposed): array
    {
        $before = $this->employees->findForManagement($ctx, $employeeId);
        if ($before === null) {
            throw new MerchantNotFoundException();
        }
        $changes = [];
        foreach ($proposed as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = $value;
            }
        }
        $protected = array_intersect(array_keys($changes), ['account', 'role_id', 'status']) !== []
            ? [$employeeId] : [];
        return AccountChangeGuard::runMany(self::APP, $protected, function () use ($ctx, $employeeId, $before, $changes): array {
            return MerchantManagementInput::write(function () use ($ctx, $employeeId, $before, $changes): array {
                $this->auth->authorizeAction($ctx, self::PERMISSION, true);
                $current = $this->employees->findForManagement($ctx, $employeeId, true);
                if ($current === null) {
                    throw new AuthUnavailableException();
                }
                if ($current !== $before) {
                    // Do not enlarge a precomputed protection set or retry writes.
                    throw new AuthUnavailableException();
                }
                if ($changes === []) {
                    return $current;
                }
                if (array_key_exists('role_id', $changes)
                    && $this->roles->findForManagement($ctx, $changes['role_id'], true) === null) {
                    throw new MerchantNotFoundException();
                }
                if (array_key_exists('account', $changes)
                    && $this->employees->accountExists($changes['account'], $employeeId)) {
                    throw self::invalidInput();
                }
                $now = time();
                $affected = $this->employees->updateForManagement($ctx, $employeeId, $changes, $now);
                if ($affected < 0 || $affected > 1) {
                    throw new AuthUnavailableException();
                }
                $result = $this->employees->findForManagement($ctx, $employeeId);
                if ($result === null || $result !== array_replace($current, $changes, ['update_time' => $now])) {
                    throw new AuthUnavailableException();
                }
                return $result;
            });
        });
    }

    private static function account(mixed $value): string
    {
        if (!is_string($value)) {
            throw self::invalidInput();
        }
        // This shared normalizer checks UTF-8 before ASCII trim/folding and length.
        return EmployeeAuthServices::normalizeAccount($value);
    }

    private static function invalidInput(): HttpException
    {
        return new HttpException(422, '请求参数不正确', null, [], 422);
    }
}
