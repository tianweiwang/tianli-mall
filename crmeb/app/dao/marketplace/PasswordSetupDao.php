<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\model\marketplace\PasswordSetup;
use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthUnavailableException;
use InvalidArgumentException;
use LogicException;
use PDO;
use think\db\Query;
use think\Model;

/** A fixed-purpose capability read never accepts a client-supplied merchant selector. */
final class PasswordSetupDao
{
    private const FIELDS = ['id', 'mer_id', 'employee_id', 'expires_at', 'consumed_at', 'add_time'];

    public function findForSetup(string $tokenHash): ?array
    {
        return $this->read($this->lookup($tokenHash));
    }

    public function lockForSetup(string $tokenHash): ?array
    {
        $query = $this->lookup($tokenHash);
        self::assertTransaction($query);
        return $this->read($query->lock(true));
    }

    /** The caller must check affected rows = 1 and commit only with the password update. */
    public function consumeForSetup(int $setupId, int $merId, int $employeeId, int $now): int
    {
        if ($setupId <= 0 || $merId <= 0 || $employeeId <= 0 || $now <= 0 || $now > 4294967295) {
            throw new InvalidArgumentException('Invalid password setup identity');
        }
        $query = (new PasswordSetup())->db()->where('id', '=', $setupId)->where('mer_id', '=', $merId)
            ->where('employee_id', '=', $employeeId)->whereNull('consumed_at')->where('expires_at', '>', $now);
        self::assertTransaction($query);
        return $query->update(['consumed_at' => $now]);
    }

    public function createForEmployee(MerchantContext $ctx, int $employeeId, string $raw32Hash, int $now): int
    {
        if ($employeeId <= 0 || strlen($raw32Hash) !== 32 || $now <= 0 || $now > 4294967295 - 86400) {
            throw new InvalidArgumentException('Invalid password setup creation');
        }
        $query = (new PasswordSetup())->db();
        self::assertTransaction($query);
        $id = (int) $query->insertGetId([
            'mer_id' => $ctx->merId,
            'employee_id' => $employeeId,
            'token_hash' => $raw32Hash,
            'expires_at' => $now + 86400,
            'consumed_at' => null,
            'add_time' => $now,
        ]);
        if ($id <= 0) {
            throw new AuthUnavailableException();
        }
        return $id;
    }

    public function createForPlatform(PlatformScope $scope, int $employeeId, string $raw32Hash, int $now): int
    {
        if ($scope->targetMerId === null || $scope->targetMerId > 4294967295
            || $scope->permission !== 'marketplace-merchants-admin-employee'
            || $employeeId <= 0 || strlen($raw32Hash) !== 32 || $now <= 0 || $now > 4294967295 - 86400) {
            throw new InvalidArgumentException('Invalid platform password setup creation');
        }
        $query = (new PasswordSetup())->db();
        self::assertTransaction($query);
        $id = (int)$query->insertGetId([
            'mer_id' => $scope->targetMerId, 'employee_id' => $employeeId, 'token_hash' => $raw32Hash,
            'expires_at' => $now + 86400, 'consumed_at' => null, 'add_time' => $now,
        ]);
        if ($id <= 0) {
            throw new AuthUnavailableException();
        }
        return $id;
    }

    /** Revoke unused capabilities and insert their replacement under the caller's merchant lock. */
    public function reissueForPlatform(PlatformScope $scope, int $employeeId, string $raw32Hash, int $now): int
    {
        if ($scope->targetMerId === null || $scope->targetMerId > 4294967295
            || $scope->permission !== 'marketplace-merchants-password-setup-reissue'
            || $employeeId <= 0 || $employeeId > 4294967295 || strlen($raw32Hash) !== 32
            || $now <= 0 || $now > 4294967295 - 86400) {
            throw new InvalidArgumentException('Invalid platform password setup reissue');
        }
        $base = $scope->apply((new PasswordSetup())->db()->where('employee_id', '=', $employeeId));
        self::assertTransaction($base);
        $pending = (clone $base)->whereNull('consumed_at');
        $before = $this->reissueRows((clone $pending)->field(self::FIELDS)->order('id', 'asc')->lock(true));
        foreach ($before as $row) {
            if ($row['mer_id'] !== $scope->targetMerId || $row['employee_id'] !== $employeeId
                || $row['consumed_at'] !== null || $row['add_time'] > $now
                || $row['expires_at'] !== $row['add_time'] + 86400) {
                throw new AuthUnavailableException();
            }
        }
        $invalidated = count($before);
        if ($invalidated > 4294967295) {
            throw new AuthUnavailableException();
        }
        if ($invalidated > 0) {
            $ids = array_column($before, 'id');
            if ((clone $pending)->whereIn('id', $ids)->update(['consumed_at' => $now]) !== $invalidated) {
                throw new AuthUnavailableException();
            }
            $expected = array_map(static fn(array $row): array => array_replace($row, ['consumed_at' => $now]), $before);
            $after = $this->reissueRows((clone $base)->whereIn('id', $ids)->field(self::FIELDS)->order('id', 'asc'));
            if ($after !== $expected) {
                throw new AuthUnavailableException();
            }
        }
        if ((int)(clone $pending)->count() !== 0) {
            throw new AuthUnavailableException();
        }
        $query = (new PasswordSetup())->db();
        self::assertTransaction($query);
        $id = (int)$query->insertGetId([
            'mer_id' => $scope->targetMerId, 'employee_id' => $employeeId, 'token_hash' => $raw32Hash,
            'expires_at' => $now + 86400, 'consumed_at' => null, 'add_time' => $now,
        ]);
        if ($id <= 0 || $id > 4294967295) {
            throw new AuthUnavailableException();
        }
        $expected = [['id' => $id, 'mer_id' => $scope->targetMerId, 'employee_id' => $employeeId,
            'expires_at' => $now + 86400, 'consumed_at' => null, 'add_time' => $now]];
        if ($this->reissueRows($this->lookup($raw32Hash)) !== $expected
            || $this->reissueRows((clone $pending)->field(self::FIELDS)->order('id', 'asc')) !== $expected) {
            throw new AuthUnavailableException();
        }
        return $invalidated;
    }

    private function reissueRows(Query $query): array
    {
        $rows = [];
        foreach ($query->select() as $row) {
            $data = $row instanceof Model ? $row->getData() : $row;
            $result = [];
            foreach (self::FIELDS as $field) {
                if (!is_array($data) || !array_key_exists($field, $data)) {
                    throw new AuthUnavailableException();
                }
                $value = $data[$field];
                if ($field === 'consumed_at' && $value === null) {
                    $result[$field] = null;
                    continue;
                }
                if ((!is_int($value) && (!is_string($value) || !ctype_digit($value)))
                    || (int)$value <= 0 || (int)$value > 4294967295) {
                    throw new AuthUnavailableException();
                }
                $result[$field] = (int)$value;
            }
            $rows[] = $result;
        }
        return $rows;
    }

    private function lookup(string $tokenHash): Query
    {
        // The service supplies the raw 32-byte SHA-256 of its canonical capability.
        if (strlen($tokenHash) !== 32) {
            throw new InvalidArgumentException('Invalid password setup digest');
        }
        return (new PasswordSetup())->db()->where('token_hash', '=', $tokenHash)->field(self::FIELDS);
    }

    private function read(Query $query): ?array
    {
        $row = $query->find();
        $data = $row instanceof Model ? $row->getData() : $row;
        return is_array($data) && $data !== [] ? $data : null;
    }

    private static function assertTransaction(Query $query): void
    {
        $pdo = $query->getConnection()->connect();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new LogicException('Password setup requires an active transaction');
        }
    }
}
