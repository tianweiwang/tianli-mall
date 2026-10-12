<?php
declare(strict_types=1);

namespace app\services\marketplace;

use app\dao\marketplace\AuditLogDao;
use app\Request;
use app\services\system\log\SystemLogServices;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use Throwable;

/** Audit only server-generated metadata inside the caller's default transaction. */
final class AuditLogServices
{
    public function __construct(
        private readonly AuditLogDao $dao,
        private readonly SystemLogServices $platformLog
    ) {
    }

    public function record(
        string $actorType,
        int $actorId,
        ?int $merId,
        string $action,
        string $resourceType,
        string $resourceId,
        string $result,
        array $detail = []
    ): void {
        try {
            if (!in_array($actorType, ['admin', 'merchant'], true)
                || $actorId <= 0 || $actorId > 4294967295
                || ($merId !== null && ($merId <= 0 || $merId > 4294967295))) {
                throw new AuthUnavailableException();
            }
            self::identifier($action, 100);
            self::identifier($resourceType, 64);
            self::identifier($resourceId, 64);
            self::identifier($result, 16);
            self::validateDetail($detail);
            self::assertTransaction();

            if ($merId === null) {
                $this->recordPlatformRead($actorType, $actorId, $action, $resourceType, $resourceId, $result, $detail);
                return;
            }

            $this->dao->insertValidated($merId, $actorType, $actorId, $action, $resourceType, $resourceId, $result, $detail, time());
        } catch (Throwable $error) {
            // A failed audit must fail the caller's transaction, never silently pass.
            throw new AuthUnavailableException();
        }
    }

    private function recordPlatformRead(
        string $actorType,
        int $actorId,
        string $action,
        string $resourceType,
        string $resourceId,
        string $result,
        array $detail
    ): void {
        $request = app()->request;
        if (!$request instanceof Request || app('http')->getName() !== 'adminapi'
            || $actorType !== 'admin' || $resourceType !== 'merchant'
            || $result !== 'success' || $detail !== [] || $request->method() !== 'GET'
            || !in_array($action, ['marketplace-merchants-list', 'marketplace-merchants-read'], true)) {
            throw new AuthUnavailableException();
        }
        $rule = $request->rule();
        if ($rule === null || $rule->getOption('marketplace_api') !== true
            || $rule->getOption('marketplace_permission') !== $action
            || $request->isAdminLogin() !== true) {
            throw new AuthUnavailableException();
        }

        $scope = $request->platformScope();
        $requestAdminId = $request->adminId();
        if ($scope->adminId !== $actorId || $scope->permission !== $action
            || (!is_int($requestAdminId) && !is_string($requestAdminId))
            || (string) $requestAdminId !== (string) $actorId) {
            throw new AuthUnavailableException();
        }
        if (($action === 'marketplace-merchants-list' && ($scope->targetMerId !== null || $resourceId !== 'global'))
            || ($action === 'marketplace-merchants-read'
                && ($scope->targetMerId === null || $resourceId !== (string) $scope->targetMerId))) {
            throw new AuthUnavailableException();
        }

        $admin = $request->adminInfo();
        if (!is_array($admin) || !isset($admin['id'], $admin['account'])
            || (!is_int($admin['id']) && !is_string($admin['id']))
            || (string) $admin['id'] !== (string) $actorId
            || !is_string($admin['account']) || $admin['account'] === ''
            || str_contains($admin['account'], "\0") || preg_match('//u', $admin['account']) !== 1) {
            throw new AuthUnavailableException();
        }

        if ($this->platformLog->recordAdminLog($actorId, $admin['account'], 'system') !== true) {
            throw new AuthUnavailableException();
        }
        self::assertTransaction();
    }

    private static function assertTransaction(): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new AuthUnavailableException();
        }
    }

    private static function identifier(string $value, int $max): void
    {
        if ($value === '' || strlen($value) > $max
            || preg_match('/\A[a-z0-9]+(?:[._:-][a-z0-9]+)*\z/', $value) !== 1) {
            throw new AuthUnavailableException();
        }
    }

    private static function validateDetail(array $detail): void
    {
        foreach ($detail as $key => $value) {
            if ($key === 'changed_fields') {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new AuthUnavailableException();
                }
                foreach ($value as $field) {
                    if (!is_string($field) || strlen($field) > 64
                        || preg_match('/\A[a-z][a-z0-9_]*\z/', $field) !== 1) {
                        throw new AuthUnavailableException();
                    }
                }
            } elseif ($key === 'employee_id' || $key === 'role_id') {
                if (!is_int($value) || $value <= 0 || $value > 4294967295) {
                    throw new AuthUnavailableException();
                }
            } elseif ($key === 'protected_employee_count') {
                if (!is_int($value) || $value < 0 || $value > 4294967295) {
                    throw new AuthUnavailableException();
                }
            } else {
                throw new AuthUnavailableException();
            }
        }
    }
}
