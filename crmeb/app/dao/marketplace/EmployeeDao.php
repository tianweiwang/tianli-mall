<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\dao\marketplace\scope\PlatformScope;
use app\model\marketplace\Employee;
use app\model\marketplace\Merchant;
use app\model\marketplace\Role;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthUnavailableException;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PDO;
use think\db\Query;
use think\Model;
use UnexpectedValueException;

/** Authentication reads are explicit; ordinary business reads require a context. */
final class EmployeeDao
{
    private const EMPLOYEE_FIELDS = ['id', 'mer_id', 'role_id', 'account', 'pwd', 'name', 'avatar', 'status', 'is_del'];
    private const ROLE_FIELDS = ['id', 'mer_id', 'name', 'permissions'];
    private const MERCHANT_FIELDS = ['mer_id', 'review_status', 'security_locked', 'closed_at'];

    public function findIdentityByNormalizedAccount(string $account): ?array
    {
        if ($account === '') {
            throw new InvalidArgumentException('Invalid employee account');
        }
        return $this->identity($this->identityQuery()->where('e.account', '=', $account));
    }

    public function findIdentityByEmployeeId(int $employeeId): ?array
    {
        self::assertPositive($employeeId);
        return $this->identity($this->identityQuery()->where('e.id', '=', $employeeId));
    }

    /** Used only after a persisted setup capability has identified its employee. */
    public function lockIdentityForPasswordSetup(int $employeeId): ?array
    {
        self::assertPositive($employeeId);
        $query = $this->identityQuery()->where('e.id', '=', $employeeId);
        self::assertTransaction($query);
        return $this->identity($query->lock(true));
    }

    public function readSelf(MerchantContext $ctx): ?array
    {
        $query = (new Employee())->db()->where('id', '=', $ctx->employeeId)->where('is_del', '=', 0);
        $query = MerchantScope::apply($query, $ctx);
        $row = $query->field(['id', 'mer_id', 'role_id', 'account', 'name', 'avatar', 'status'])->find();
        $data = $row instanceof Model ? $row->getData() : $row;
        return is_array($data) && $data !== [] ? $data : null;
    }

    /** The caller must check affected rows = 1 and consume the capability in this transaction. */
    public function initializePasswordForSetup(int $employeeId, int $merId, string $passwordHash, int $now): int
    {
        self::assertPositive($employeeId);
        self::assertPositive($merId);
        if ($now <= 0 || $now > 4294967295 || $passwordHash === '' || strlen($passwordHash) > 255) {
            throw new InvalidArgumentException('Invalid password initialization');
        }
        $query = (new Employee())->db()->where('id', '=', $employeeId)->where('mer_id', '=', $merId)
            ->where('status', '=', 1)->where('is_del', '=', 0)->whereNull('pwd');
        self::assertTransaction($query);
        return $query->update(['pwd' => $passwordHash, 'update_time' => $now]);
    }

    private const MANAGEMENT_FIELDS = ['id', 'mer_id', 'role_id', 'account', 'name', 'avatar', 'status', 'add_time', 'update_time'];

    public function findForManagement(MerchantContext $ctx, int $employeeId, bool $lock = false): ?array
    {
        self::assertPositive($employeeId);
        $query = (new Employee())->db()->where('id', '=', $employeeId)->where('is_del', '=', 0);
        $query = MerchantScope::apply($query, $ctx);
        if ($lock) {
            self::assertTransaction($query);
            $query->lock(true);
        }
        return self::managementData($query->field(self::MANAGEMENT_FIELDS)->find());
    }

    public function listForManagement(MerchantContext $ctx, int $page, int $limit, ?int $status, ?int $roleId): array
    {
        if ($page <= 0 || $limit <= 0 || $limit > 100
            || ($status !== null && !in_array($status, [0, 1], true))
            || ($roleId !== null && $roleId <= 0)) {
            throw new InvalidArgumentException('Invalid employee listing');
        }
        $query = (new Employee())->db()->where('is_del', '=', 0);
        if ($status !== null) {
            $query->where('status', '=', $status);
        }
        if ($roleId !== null) {
            $query->where('role_id', '=', $roleId);
        }
        $query = MerchantScope::apply($query, $ctx);
        $count = (int) (clone $query)->count();
        $rows = (clone $query)->field(self::MANAGEMENT_FIELDS)->order('id', 'asc')->page($page, $limit)->select();
        $list = [];
        foreach ($rows as $row) {
            $data = self::managementData($row);
            if ($data === null) {
                throw new AuthUnavailableException();
            }
            $list[] = $data;
        }
        return ['list' => $list, 'count' => $count];
    }

    /** Include every binding, including disabled and soft-deleted employees. */
    public function idsForRole(MerchantContext $ctx, int $roleId, bool $lock = false): array
    {
        self::assertPositive($roleId);
        $query = (new Employee())->db()->where('role_id', '=', $roleId);
        $query = MerchantScope::apply($query, $ctx)->order('id', 'asc');
        if ($lock) {
            self::assertTransaction($query);
            $query->lock(true);
        }
        $ids = [];
        foreach ($query->column('id') as $value) {
            $id = (int) $value;
            if ($id <= 0) {
                throw new AuthUnavailableException();
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /** Exact uniqueness probe only: it returns no subject or credential data. */
    public function accountExists(string $normalized, ?int $excludeId = null): bool
    {
        if ($normalized === '') {
            throw new InvalidArgumentException('Invalid employee account');
        }
        $query = (new Employee())->db()->where('account', '=', $normalized);
        if ($excludeId !== null) {
            self::assertPositive($excludeId);
            $query->where('id', '<>', $excludeId);
        }
        return (int) $query->count() > 0;
    }

    public function createForMerchant(MerchantContext $ctx, array $validatedFields, int $now): int
    {
        self::assertTimestamp($now);
        if (array_diff(array_keys($validatedFields), ['account', 'role_id', 'name', 'avatar']) !== []
            || !isset($validatedFields['account'], $validatedFields['role_id'])
            || !is_string($validatedFields['account']) || $validatedFields['account'] === ''
            || !is_int($validatedFields['role_id']) || $validatedFields['role_id'] <= 0) {
            throw new InvalidArgumentException('Invalid employee creation');
        }
        $query = (new Employee())->db();
        self::assertTransaction($query);
        $id = (int) $query->insertGetId([
            'mer_id' => $ctx->merId,
            'role_id' => $validatedFields['role_id'],
            'account' => $validatedFields['account'],
            'pwd' => null,
            'name' => $validatedFields['name'] ?? '',
            'avatar' => $validatedFields['avatar'] ?? '',
            'status' => 1,
            'is_del' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw new AuthUnavailableException();
        }
        return $id;
    }

    public function updateForManagement(MerchantContext $ctx, int $employeeId, array $changes, int $now): int
    {
        self::assertPositive($employeeId);
        self::assertTimestamp($now);
        if (array_diff(array_keys($changes), ['account', 'role_id', 'name', 'avatar', 'status']) !== []) {
            throw new InvalidArgumentException('Invalid employee update');
        }
        $query = (new Employee())->db()->where('id', '=', $employeeId)->where('is_del', '=', 0);
        $query = MerchantScope::apply($query, $ctx);
        self::assertTransaction($query);
        if ($changes === []) {
            return 0;
        }
        $changes['update_time'] = $now;
        return $query->update($changes);
    }

    /** Every binding participates in merchant eligibility changes. */
    public function idsForPlatformMerchant(PlatformScope $scope, bool $lock = false): array
    {
        self::assertPlatformTarget($scope);
        $query = $scope->apply((new Employee())->db())->order('id', 'asc');
        if ($lock) {
            self::assertTransaction($query);
            $query->lock(true);
        }
        $ids = [];
        foreach ($query->column('id') as $value) {
            $id = (int)$value;
            if ($id <= 0) {
                throw new AuthUnavailableException();
            }
            $ids[] = $id;
        }
        return $ids;
    }

    public function findForPlatform(PlatformScope $scope, int $employeeId, bool $lock = false): ?array
    {
        self::assertPlatformTarget($scope);
        self::assertPositive($employeeId);
        $query = $scope->apply((new Employee())->db()->where('id', '=', $employeeId)->where('is_del', '=', 0))
            ->field(self::MANAGEMENT_FIELDS);
        if ($lock) {
            self::assertTransaction($query);
            $query->lock(true);
        }
        return self::managementData($query->find());
    }

    public function createForPlatform(PlatformScope $scope, array $fields, int $now): int
    {
        self::assertPlatformTarget($scope);
        self::assertTimestamp($now);
        if ($scope->permission !== 'marketplace-merchants-admin-employee'
            || array_keys($fields) !== ['account', 'role_id', 'name', 'avatar']
            || !is_string($fields['account']) || $fields['account'] === ''
            || !is_int($fields['role_id']) || $fields['role_id'] <= 0
            || !is_string($fields['name']) || !is_string($fields['avatar'])) {
            throw new InvalidArgumentException('Invalid platform employee creation');
        }
        $query = (new Employee())->db();
        self::assertTransaction($query);
        $id = (int)$query->insertGetId([
            'mer_id' => $scope->targetMerId, 'role_id' => $fields['role_id'],
            'account' => $fields['account'], 'pwd' => null, 'name' => $fields['name'], 'avatar' => $fields['avatar'],
            'status' => 1, 'is_del' => 0, 'add_time' => $now, 'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw new AuthUnavailableException();
        }
        return $id;
    }

    private static function assertPlatformTarget(PlatformScope $scope): void
    {
        if ($scope->targetMerId === null || $scope->targetMerId > 4294967295) {
            throw new InvalidArgumentException('Platform employee merchant is required');
        }
    }

    private static function managementData(array|Model|null $row): ?array
    {
        $data = $row instanceof Model ? $row->getData() : $row;
        if (!is_array($data) || $data === []) {
            return null;
        }
        $result = [];
        foreach (self::MANAGEMENT_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw new AuthUnavailableException();
            }
            $result[$field] = in_array($field, ['id', 'mer_id', 'role_id', 'status', 'add_time', 'update_time'], true)
                ? (int) $data[$field] : (string) $data[$field];
        }
        return $result;
    }

    private static function assertTimestamp(int $now): void
    {
        if ($now <= 0 || $now > 4294967295) {
            throw new InvalidArgumentException('Invalid employee timestamp');
        }
    }

    /** Same-merchant joins are part of identity establishment, not a client selector. */
    private function identityQuery(): Query
    {
        $roleTable = (new Role())->db()->getTable();
        $merchantTable = (new Merchant())->db()->getTable();
        $fields = [];
        foreach (self::EMPLOYEE_FIELDS as $field) {
            $fields['e.' . $field] = 'employee_' . $field;
        }
        foreach (self::ROLE_FIELDS as $field) {
            $fields['r.' . $field] = 'role_' . $field;
        }
        foreach (self::MERCHANT_FIELDS as $field) {
            $fields['m.' . $field] = 'merchant_' . $field;
        }
        return (new Employee())->db()->alias('e')
            ->join([$roleTable => 'r'], 'r.id = e.role_id AND r.mer_id = e.mer_id', 'INNER')
            ->join([$merchantTable => 'm'], 'm.mer_id = e.mer_id', 'INNER')->field($fields);
    }

    private function identity(Query $query): ?array
    {
        $row = $query->find();
        $data = $row instanceof Model ? $row->getData() : $row;
        if (!is_array($data) || $data === []) {
            return null;
        }
        $identity = [];
        foreach (['employee' => self::EMPLOYEE_FIELDS, 'role' => self::ROLE_FIELDS,
            'merchant' => self::MERCHANT_FIELDS] as $section => $fields) {
            $identity[$section] = [];
            foreach ($fields as $field) {
                $key = $section . '_' . $field;
                if (!array_key_exists($key, $data)) {
                    throw new UnexpectedValueException('Incomplete employee identity storage');
                }
                $identity[$section][$field] = $data[$key];
            }
        }
        $storedPermissions = $identity['role']['permissions'];
        if (!is_string($storedPermissions)) {
            throw new UnexpectedValueException('Invalid role permission storage');
        }
        try {
            $permissions = json_decode($storedPermissions, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('Invalid role permission storage');
        }
        if (!is_array($permissions) || !array_is_list($permissions)) {
            throw new UnexpectedValueException('Invalid role permission storage');
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission)) {
                throw new UnexpectedValueException('Invalid role permission storage');
            }
        }
        $identity['role']['permissions'] = $permissions;
        return $identity;
    }

    private static function assertPositive(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid employee identity');
        }
    }

    private static function assertTransaction(Query $query): void
    {
        $pdo = $query->getConnection()->connect();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new LogicException('Password setup requires an active transaction');
        }
    }
}
