<?php
declare(strict_types=1);

namespace app\services\marketplace\context;

use app\dao\marketplace\EmployeeDao;
use app\services\marketplace\MerchantPermissions;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthSessionException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\auth\AuthSessionStore;
use Throwable;

/** The shared service holds a DAO, never an employee or merchant identity. */
final readonly class MerchantJobContext
{
    public function __construct(private EmployeeDao $employees) {}

    public function rebuild(int $employeeId, string $requestId): MerchantContext
    {
        if ($employeeId <= 0 || $employeeId > 4294967295
            || preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1) {
            throw new AuthSessionException();
        }
        try {
            $identity = $this->employees->findIdentityByEmployeeId($employeeId);
            if ($identity === null || (int)$identity['employee']['id'] !== $employeeId
                || (int)$identity['employee']['mer_id'] <= 0
                || (int)$identity['employee']['mer_id'] > 4294967295
                || (int)$identity['employee']['role_id'] !== (int)$identity['role']['id']
                || (int)$identity['employee']['mer_id'] !== (int)$identity['role']['mer_id']
                || (int)$identity['employee']['mer_id'] !== (int)$identity['merchant']['mer_id']
                || (int)$identity['employee']['status'] !== 1
                || (int)$identity['employee']['is_del'] !== 0
                || (int)$identity['merchant']['review_status'] !== 1
                || (int)$identity['merchant']['security_locked'] !== 0
                || $identity['merchant']['closed_at'] !== null
                || !is_string($identity['employee']['pwd']) || $identity['employee']['pwd'] === '') {
                throw new AuthSessionException();
            }
            $permissions = MerchantPermissions::validate($identity['role']['permissions']);
            $version = AuthSessionStore::fromConfig()->currentAccountVersion('merchantapi', (string)$employeeId);
            return new MerchantContext((int)$identity['employee']['mer_id'], $employeeId,
                $permissions, $version, $requestId);
        } catch (AuthException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AuthUnavailableException();
        }
    }
}
