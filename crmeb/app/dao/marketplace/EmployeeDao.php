<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\dao\marketplace\scope\MerchantScope;
use app\model\marketplace\Employee;
use app\model\marketplace\Merchant;
use app\model\marketplace\Role;
use app\services\marketplace\context\MerchantContext;
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
