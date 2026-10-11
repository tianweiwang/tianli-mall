<?php
declare(strict_types=1);

namespace app\services\marketplace;

use InvalidArgumentException;

final class MerchantPermissions
{
    public const ALL = [
        'merchant.profile.read', 'merchant.employee.manage', 'merchant.role.manage',
        'product.read', 'product.write', 'product.submit_review', 'stock.adjust',
        'attachment.manage', 'freight.manage',
    ];
    public const BUILTIN_ADMIN = self::ALL;
    public const BUILTIN_OPERATOR = [
        'merchant.profile.read', 'product.read', 'product.write',
        'product.submit_review', 'stock.adjust', 'attachment.manage', 'freight.manage',
    ];

    /** Return scalar values detached from the caller's permission array. */
    public static function validate(array $permissions): array
    {
        if (!array_is_list($permissions)) {
            throw new InvalidArgumentException('Invalid merchant permissions');
        }
        $validated = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission) || !in_array($permission, self::ALL, true)) {
                throw new InvalidArgumentException('Invalid merchant permissions');
            }
            $validated[$permission] = (string)$permission;
        }
        return array_values($validated);
    }
}
