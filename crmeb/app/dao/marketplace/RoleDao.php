<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\dao\marketplace\scope\PlatformScope;
use app\model\marketplace\Role;
use app\services\marketplace\context\MerchantContext;
use InvalidArgumentException;
use LogicException;
use PDO;
use think\db\Query;
use think\facade\Db;
use think\Model;
use UnexpectedValueException;

/** Roles remain within the supplied merchant; action checks belong to services. */
final class RoleDao
{
    private const MANAGEMENT_FIELDS = ['id', 'mer_id', 'name', 'permissions', 'add_time', 'update_time'];

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

    public function findForManagement(MerchantContext $ctx, int $roleId, bool $lock = false): ?array
    {
        self::assertPositive($roleId);
        $query = (new Role())->db()->where('id', '=', $roleId);
        $query = MerchantScope::apply($query, $ctx)->field(self::MANAGEMENT_FIELDS);
        if ($lock) {
            self::assertTransaction($query);
            $query->lock(true);
        }
        return self::managementRow($query->find());
    }

    public function listForManagement(MerchantContext $ctx, int $page, int $limit): array
    {
        if ($page <= 0 || $page > 2147483647 || $limit <= 0 || $limit > 100) {
            throw new InvalidArgumentException('Invalid role pagination');
        }
        $query = MerchantScope::apply((new Role())->db(), $ctx);
        $count = (clone $query)->count();
        $rows = $query->field(self::MANAGEMENT_FIELDS)->order('id', 'asc')->page($page, $limit)->select();
        $list = [];
        foreach ($rows as $row) {
            $data = self::managementRow($row);
            if ($data === null) {
                throw new UnexpectedValueException('Incomplete role storage');
            }
            $list[] = $data;
        }
        return ['list' => $list, 'count' => $count];
    }

    public function nameExists(MerchantContext $ctx, string $name, ?int $excludeId = null): bool
    {
        if ($name === '') {
            throw new InvalidArgumentException('Invalid role name');
        }
        $query = (new Role())->db()->where('name', '=', $name);
        if ($excludeId !== null) {
            self::assertPositive($excludeId);
            $query->where('id', '<>', $excludeId);
        }
        $query = MerchantScope::apply($query, $ctx);
        return $query->value('id') !== null;
    }

    public function createForMerchant(MerchantContext $ctx, string $name, array $permissions, int $now): int
    {
        if ($name === '') {
            throw new InvalidArgumentException('Invalid role name');
        }
        self::assertPermissions($permissions);
        self::assertTime($now);
        $query = (new Role())->db();
        self::assertTransaction($query);
        // Role::db marks permissions as JSON; the installed Builder encodes
        // this PHP list once before binding it to the JSON column.
        $id = $query->insertGetId([
            'mer_id' => $ctx->merId,
            'name' => $name,
            'permissions' => $permissions,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ((!is_int($id) && (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1))
            || (int)$id <= 0 || (int)$id > 4294967295) {
            throw new UnexpectedValueException('Invalid created role identity');
        }
        return (int)$id;
    }

    public function updateForManagement(MerchantContext $ctx, int $roleId, array $changes, int $now): int
    {
        self::assertPositive($roleId);
        self::assertTime($now);
        foreach ($changes as $field => $value) {
            if ($field === 'name') {
                if (!is_string($value) || $value === '') {
                    throw new InvalidArgumentException('Invalid role name');
                }
            } elseif ($field === 'permissions') {
                if (!is_array($value)) {
                    throw new InvalidArgumentException('Invalid role permissions');
                }
                self::assertPermissions($value);
            } else {
                throw new InvalidArgumentException('Invalid role update field');
            }
        }
        $query = (new Role())->db()->where('id', '=', $roleId);
        $query = MerchantScope::apply($query, $ctx);
        self::assertTransaction($query);
        if ($changes === []) {
            return 0;
        }
        $changes['update_time'] = $now;
        return $query->update($changes);
    }

    public function listForPlatform(PlatformScope $scope, int $merId, bool $lock = false): array
    {
        self::assertPlatformMerchant($scope, $merId);
        $query = $scope->apply((new Role())->db()->where('mer_id', '=', $merId))
            ->field(self::MANAGEMENT_FIELDS)->order('id', 'asc');
        if ($lock) {
            self::assertTransaction($query);
            $query->lock(true);
        }
        $list = [];
        foreach ($query->select() as $row) {
            $data = self::managementRow($row);
            if ($data === null) {
                throw new UnexpectedValueException('Incomplete role storage');
            }
            $list[] = $data;
        }
        return $list;
    }

    public function createForPlatform(PlatformScope $scope, int $merId, string $name, array $permissions, int $now): int
    {
        self::assertPlatformMerchant($scope, $merId);
        if ($name === '') {
            throw new InvalidArgumentException('Invalid role name');
        }
        self::assertPermissions($permissions);
        self::assertTime($now);
        $query = (new Role())->db();
        self::assertTransaction($query);
        $id = $query->insertGetId(['mer_id' => $merId, 'name' => $name, 'permissions' => $permissions,
            'add_time' => $now, 'update_time' => $now]);
        if ((!is_int($id) && (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1))
            || (int)$id <= 0 || (int)$id > 4294967295) {
            throw new UnexpectedValueException('Invalid created platform role identity');
        }
        return (int)$id;
    }

    private static function assertPlatformMerchant(PlatformScope $scope, int $merId): void
    {
        self::assertPositive($merId);
        if ($scope->targetMerId === null) {
            // The service has just inserted and locked this real new merchant.
            if ($scope->permission !== 'marketplace-merchants-create') {
                throw new InvalidArgumentException('Invalid platform role creation scope');
            }
        } elseif ($scope->targetMerId !== $merId || $scope->permission !== 'marketplace-merchants-admin-employee') {
            throw new InvalidArgumentException('Invalid platform role merchant');
        }
    }

    private static function managementRow(array|Model|null $row): ?array
    {
        $data = $row instanceof Model ? $row->getData() : $row;
        if ($data === null || $data === []) {
            return null;
        }
        $public = [];
        foreach (self::MANAGEMENT_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw new UnexpectedValueException('Incomplete role storage');
            }
            $public[$field] = $data[$field];
        }
        foreach (['id', 'mer_id', 'add_time', 'update_time'] as $field) {
            $value = $public[$field];
            if ((!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1))
                || (int)$value <= 0 || (int)$value > 4294967295) {
                throw new UnexpectedValueException('Invalid role storage');
            }
            $public[$field] = (int)$value;
        }
        if (!is_string($public['name']) || $public['name'] === '') {
            throw new UnexpectedValueException('Invalid role name storage');
        }
        if (!is_array($public['permissions']) || !array_is_list($public['permissions'])) {
            throw new UnexpectedValueException('Invalid role permission storage');
        }
        foreach ($public['permissions'] as $permission) {
            if (!is_string($permission)) {
                throw new UnexpectedValueException('Invalid role permission storage');
            }
        }
        return $public;
    }

    private static function assertPermissions(array $permissions): void
    {
        if (!array_is_list($permissions)) {
            throw new InvalidArgumentException('Invalid role permissions');
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                throw new InvalidArgumentException('Invalid role permissions');
            }
        }
    }

    private static function assertPositive(int $id): void
    {
        if ($id <= 0 || $id > 4294967295) {
            throw new InvalidArgumentException('Invalid role identity');
        }
    }

    private static function assertTime(int $now): void
    {
        if ($now <= 0 || $now > 4294967295) {
            throw new InvalidArgumentException('Invalid role timestamp');
        }
    }

    private static function assertTransaction(Query $query): void
    {
        $connection = $query->getConnection();
        $pdo = $connection->connect();
        if ($connection !== Db::connect() || !$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new LogicException('Role write requires the default active transaction');
        }
    }
}
