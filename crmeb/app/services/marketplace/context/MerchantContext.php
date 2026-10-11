<?php
declare(strict_types=1);

namespace app\services\marketplace\context;

use app\services\marketplace\MerchantPermissions;
use crmeb\exceptions\MerchantForbiddenException;
use InvalidArgumentException;

/** Created by the server after current identity and merchant checks. */
final readonly class MerchantContext
{
    public array $permissions;

    public function __construct(public int $merId, public int $employeeId, array $permissions,
        public string $sessionVersion, public string $requestId)
    {
        if ($merId <= 0 || $employeeId <= 0 || trim($sessionVersion) === '' || trim($requestId) === '') {
            throw new InvalidArgumentException('Invalid merchant context');
        }
        $this->permissions = MerchantPermissions::validate($permissions);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new MerchantForbiddenException();
        }
    }
}
