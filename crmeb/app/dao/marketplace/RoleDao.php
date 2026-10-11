<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\model\marketplace\Role;
use app\services\marketplace\context\MerchantContext;
use InvalidArgumentException;
use think\Model;
use UnexpectedValueException;

/** Roles remain within the supplied merchant; action checks belong to services. */
final class RoleDao
{
    public function findForContext(MerchantContext $ctx, int $roleId): ?array
    {
        if ($roleId <= 0) {
            throw new InvalidArgumentException('Invalid role identity');
        }
        $query = (new Role())->db()->where('id', '=', $roleId);
        $query = MerchantScope::apply($query, $ctx);
        $row = $query->field(['id', 'mer_id', 'name', 'permissions'])->find();
        $data = $row instanceof Model ? $row->getData() : $row;
        if (!is_array($data) || $data === []) {
            return null;
        }
        if (!is_array($data['permissions'] ?? null) || !array_is_list($data['permissions'])) {
            throw new UnexpectedValueException('Invalid role permission storage');
        }
        foreach ($data['permissions'] as $permission) {
            if (!is_string($permission)) {
                throw new UnexpectedValueException('Invalid role permission storage');
            }
        }
        // MerchantPermissions validates the current catalog in the auth service.
        return $data;
    }
}
